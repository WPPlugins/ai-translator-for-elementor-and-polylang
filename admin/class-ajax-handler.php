<?php
/**
 * Enhanced AJAX Handler with Error Handling and Cost Estimation
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Ajax_Handler {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Translation actions
        add_action('wp_ajax_aitrfoel_get_pages', [$this, 'get_pages']);
        add_action('wp_ajax_aitrfoel_translate_page', [$this, 'translate_page']);
        add_action('wp_ajax_aitrfoel_estimate_cost', [$this, 'estimate_cost']);
        add_action('wp_ajax_aitrfoel_get_usage_stats', [$this, 'get_usage_stats']);
        
        // Bulk translation actions
        add_action('wp_ajax_aitrfoel_pre_analyze_bulk', [$this, 'handle_pre_analyze_bulk']); // NEW: Pre-flight analysis
        add_action('wp_ajax_aitrfoel_start_bulk_translation', [$this, 'handle_start_bulk_translation']);
        add_action('wp_ajax_aitrfoel_process_bulk_item', [$this, 'handle_process_bulk_item']); // Per-item processing (avoids 504)
        add_action('wp_ajax_aitrfoel_submit_bulk_item',   [$this, 'handle_submit_bulk_item']);   // Async Phase 1: parse + enqueue on proxy
        add_action('wp_ajax_aitrfoel_poll_bulk_item',     [$this, 'handle_poll_bulk_item']);     // Async Phase 2: check proxy + apply
        add_action('wp_ajax_aitrfoel_get_translation_status', [$this, 'handle_get_translation_status']);
        add_action('wp_ajax_aitrfoel_pause_translation', [$this, 'handle_pause_translation']);
        add_action('wp_ajax_aitrfoel_resume_translation', [$this, 'handle_resume_translation']);
        add_action('wp_ajax_aitrfoel_cancel_translation', [$this, 'handle_cancel_translation']);
        add_action('wp_ajax_aitrfoel_publish_translations', [$this, 'handle_publish_translations']);
        add_action('wp_ajax_aitrfoel_get_word_count', [$this, 'handle_get_word_count']);
        add_action('wp_ajax_aitrfoel_prescan_word_counts', [$this, 'handle_prescan_word_counts']);
        
        // Page management actions
        add_action('wp_ajax_aitrfoel_delete_page', [$this, 'handle_delete_page']);
        add_action('wp_ajax_aitrfoel_bulk_delete_translations', [$this, 'handle_bulk_delete_translations']);
        add_action('wp_ajax_aitrfoel_delete_page_translations', [$this, 'handle_delete_page_translations']);
        add_action('wp_ajax_aitrfoel_publish_page', [$this, 'handle_publish_page']);
        add_action('wp_ajax_aitrfoel_retranslate_post', [$this, 'handle_retranslate_post']);
        add_action('wp_ajax_aitrfoel_repair_template_shortcodes', [$this, 'handle_repair_template_shortcodes']);
        
        // Settings actions
        add_action('wp_ajax_aitrfoel_get_translation_settings', [$this, 'handle_get_translation_settings']);
        add_action('wp_ajax_aitrfoel_save_translation_settings', [$this, 'handle_save_translation_settings']);
        add_action('wp_ajax_aitrfoel_get_smart_suggestions', [$this, 'handle_get_smart_suggestions']);
        add_action('wp_ajax_aitrfoel_debug_exclusions', [$this, 'handle_debug_exclusions']);
        
        // Cache management
        add_action('wp_ajax_aitrfoel_clear_cache', [$this, 'clear_cache']);
        add_action('wp_ajax_aitrfoel_get_cache_stats', [$this, 'get_cache_stats']);
        
        // Debug endpoint
        add_action('wp_ajax_aitrfoel_debug_test', [$this, 'handle_debug_test']);
        add_action('wp_ajax_aitrfoel_force_cron', [$this, 'handle_force_cron']);
        add_action('wp_ajax_aitrfoel_process_pending', [$this, 'handle_process_pending']);
        add_action('wp_ajax_aitrfoel_test_server_usage', [$this, 'handle_test_server_usage']); // NEW: Test server usage
        
        // Monitor endpoints
        add_action('wp_ajax_aitrfoel_monitor_jobs', [$this, 'handle_monitor_jobs']);
        add_action('wp_ajax_aitrfoel_get_system_logs', [$this, 'handle_get_system_logs']);
        add_action('wp_ajax_aitrfoel_refresh_table_data', [$this, 'handle_refresh_table_data']);
        
        // Review & Upgrade Prompts
        add_action('wp_ajax_aitrfoel_dismiss_review', [$this, 'handle_dismiss_review']);
        add_action('wp_ajax_aitrfoel_dismiss_upgrade_banner', [$this, 'handle_dismiss_upgrade_banner']);
        add_action('wp_ajax_aitrfoel_record_translation_success', [$this, 'handle_record_translation_success']);

        // Legacy support
        add_action('wp_ajax_aitrfoel_publish_translation', [$this, 'handle_publish_translation']);
        add_action('wp_ajax_aitrfoel_get_preview_url', [$this, 'handle_get_preview_url']);

        // Auto-translate menus & widgets
        add_action('wp_ajax_aitrfoel_auto_translate_menus', [$this, 'handle_auto_translate_menus']);
        add_action('wp_ajax_aitrfoel_auto_translate_widgets', [$this, 'handle_auto_translate_widgets']);

        // Auto-translate Elementor templates (headers/footers)
        add_action('wp_ajax_aitrfoel_auto_translate_templates', [$this, 'handle_auto_translate_templates']);

        // SEO meta patch tool (bulk re-translate SEO fields on existing translations)
        add_action('wp_ajax_aitrfoel_seo_patch_count', [$this, 'handle_seo_patch_count']);
        add_action('wp_ajax_aitrfoel_seo_patch_batch', [$this, 'handle_seo_patch_batch']);
        add_action('wp_ajax_aitrfoel_seo_check',       [$this, 'handle_seo_check']);

        // Excerpt / short description patch tool (WooCommerce section)
        add_action('wp_ajax_aitrfoel_excerpt_patch_count', [$this, 'handle_excerpt_patch_count']);
        add_action('wp_ajax_aitrfoel_excerpt_patch_batch', [$this, 'handle_excerpt_patch_batch']);

        // Repair translation links (reconcile source-meta + group table + language terms)
        add_action('wp_ajax_aitrfoel_repair_links', [$this, 'handle_repair_links']);
        add_action('wp_ajax_aitrfoel_repair_content_links', [$this, 'handle_repair_content_links']);

        // Auto-translate the String Translations table (WooCommerce/admin strings)
        add_action('wp_ajax_aitrfoel_autotranslate_strings', [$this, 'handle_autotranslate_strings']);
        add_action('wp_ajax_aitrfoel_scan_forms', [$this, 'handle_scan_forms']);
        add_action('wp_ajax_aitrfoel_switch_default_language', [$this, 'handle_switch_default_language']);
        add_action('wp_ajax_aitrfoel_localize_media_library', [$this, 'handle_localize_media_library']);
        add_action('wp_ajax_aitrfoel_detect_site_tone', [$this, 'handle_detect_site_tone']);

        // Live language-switcher preview (renders with unsaved form values)
        add_action('wp_ajax_aitrfoel_switcher_preview', [$this, 'handle_switcher_preview']);

        // Read-only Elementor structure inspector (diagnostics for v4 atomic widgets)
        add_action('wp_ajax_aitrfoel_inspect_elementor', [$this, 'handle_inspect_elementor']);

        // Limit reached notice and viral bonus claim
        add_action('wp_ajax_aitrfoel_dismiss_limit_notice', [$this, 'handle_dismiss_limit_notice']);
        add_action('wp_ajax_aitrfoel_claim_share_bonus', [$this, 'handle_claim_share_bonus']);
    }

    /**
     * Read-only diagnostic: summarize a post's _elementor_data structure — each
     * element's widgetType, its settings keys, and the shape of each setting value
     * (plain string, or a typed/atomic prop object). Used to add Elementor v4
     * atomic-widget translation support.
     */
    public function handle_inspect_elementor() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $pid = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($pid <= 0) {
            wp_send_json_error('Enter a valid post/template ID.');
        }
        $raw = get_post_meta($pid, '_elementor_data', true);
        if (empty($raw)) {
            wp_send_json_error('No _elementor_data found for #' . $pid . '.');
        }
        $arr = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($arr)) {
            wp_send_json_error('Could not parse _elementor_data for #' . $pid . '.');
        }

        $lines = [];
        $describe_value = function ($v) {
            if (is_string($v)) {
                $s = trim($v);
                return $s === '' ? '""' : '"' . mb_substr($s, 0, 60) . (mb_strlen($s) > 60 ? '…' : '') . '"';
            }
            if (is_array($v)) {
                if (isset($v['$$type'])) {
                    $inner = '';
                    if (array_key_exists('value', $v)) {
                        $val = $v['value'];
                        $inner = is_string($val) ? ' value="' . mb_substr(trim($val), 0, 60) . '"' : ' value=' . gettype($val);
                    }
                    return '{$$type:' . $v['$$type'] . $inner . '}';
                }
                return 'array(' . implode(',', array_slice(array_keys($v), 0, 6)) . ')';
            }
            return gettype($v);
        };
        $walk = function ($els, $depth) use (&$walk, &$lines, $describe_value) {
            foreach ((array) $els as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $type = isset($el['widgetType']) ? $el['widgetType'] : (isset($el['elType']) ? $el['elType'] : '?');
                $settings = isset($el['settings']) && is_array($el['settings']) ? $el['settings'] : [];
                $parts = [];
                foreach ($settings as $k => $v) {
                    $parts[] = $k . '=' . $describe_value($v);
                }
                $lines[] = str_repeat('  ', $depth) . '• ' . $type
                    . ($parts ? ' :: ' . implode('  |  ', array_slice($parts, 0, 12)) : '');
                if (!empty($el['elements'])) {
                    $walk($el['elements'], $depth + 1);
                }
            }
        };
        $walk($arr, 0);

        wp_send_json_success([
            'post_id'           => $pid,
            'elementor_version' => get_post_meta($pid, '_elementor_version', true),
            'report'            => implode("\n", $lines),
        ]);
    }

    /**
     * Render the language switcher with the CURRENT (unsaved) form values, so the
     * settings preview can update live. Overrides the relevant options for the
     * duration of the render via pre_option filters (nothing is persisted).
     */
    public function handle_switcher_preview() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('not_active');
        }

        $style        = sanitize_text_field($_POST['style'] ?? 'flags-text');
        $custom_names = [];
        if (!empty($_POST['custom_names']) && is_array($_POST['custom_names'])) {
            foreach ($_POST['custom_names'] as $k => $v) {
                $custom_names[sanitize_key($k)] = sanitize_text_field(wp_unslash($v));
            }
        }

        $overrides = [
            'aitrfoel_switcher_enabled'          => true,
            'aitrfoel_switcher_style'            => $style,
            'aitrfoel_switcher_show_flags'       => !empty($_POST['show_flags']),
            'aitrfoel_switcher_show_names'       => !empty($_POST['show_names']),
            'aitrfoel_switcher_name_display'     => sanitize_text_field($_POST['name_display'] ?? 'native'),
            'aitrfoel_switcher_hide_untranslated' => !empty($_POST['hide_untranslated']),
            'aitrfoel_switcher_custom_names'     => $custom_names,
        ];

        $added = [];
        foreach ($overrides as $opt => $val) {
            $cb = function () use ($val) { return $val; };
            add_filter('pre_option_' . $opt, $cb);
            $added[$opt] = $cb;
        }

        $html = AITRFOEL_Builtin_Integration::get_instance()->render_language_switcher_shortcode(['style' => $style]);

        foreach ($added as $opt => $cb) {
            remove_filter('pre_option_' . $opt, $cb);
        }

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Auto-translate the custom String Translations table. Collects source
     * strings (saved entries + the site's WooCommerce gateway titles/descriptions
     * and checkout privacy text) and fills each secondary language via the
     * translation API, batching per language. Skips already-filled cells unless
     * force=1. Saves into the aitrfoel_string_translations option.
     */
    public function handle_autotranslate_strings() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $force   = !empty($_POST['force']);
        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        $default = $builtin->get_default_language();

        // Secondary languages.
        $secondary = [];
        foreach ($builtin->get_available_languages() as $l) {
            if (isset($l->code) && $l->code !== $default) {
                $secondary[] = $l->code;
            }
        }
        if (empty($secondary)) {
            wp_send_json_error('No secondary languages configured.');
        }

        // Existing saved translations, keyed by source.
        $map = [];
        $sources = [];
        $entries = get_option('aitrfoel_string_translations', []);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (empty($e['source'])) {
                    continue;
                }
                $src = trim($e['source']);
                $map[$src] = (!empty($e['translations']) && is_array($e['translations'])) ? $e['translations'] : [];
                $sources[] = $src;
            }
        }

        // Add the site's WooCommerce strings as sources.
        if (function_exists('WC')) {
            $priv = get_option('woocommerce_checkout_privacy_policy_text', '');
            if (is_string($priv) && trim($priv) !== '') {
                $sources[] = trim($priv);
            }
            if (WC()->payment_gateways) {
                foreach (WC()->payment_gateways->payment_gateways() as $gw) {
                    if (!empty($gw->title))       { $sources[] = trim((string) $gw->title); }
                    if (!empty($gw->description)) { $sources[] = trim((string) $gw->description); }
                }
            }
        }
        // Collect translatable strings from every supported form and filter plugin.
        // The same grouped collector powers the "Scan strings" report, so the counts
        // shown there always match what actually gets translated here.
        foreach (self::collect_form_filter_sources() as $group_sources) {
            foreach ($group_sources as $s) {
                $sources[] = $s;
            }
        }

        // Note: SureCart products are NOT collected here. Their name/description are
        // content, not UI strings, so they're translated like WooCommerce products via
        // the dashboard's Products tab (reviewable per-product copies), not the string
        // table.

        $sources = array_values(array_unique(array_filter($sources)));
        if (empty($sources)) {
            wp_send_json_error('No strings found to translate.');
        }

        $api    = AITRFOEL_OpenAI_API::get_instance();
        $filled = 0;

        // One batch call per language (translate all pending sources at once).
        foreach ($secondary as $lang) {
            $todo = [];
            foreach ($sources as $src) {
                if ($force || empty($map[$src][$lang])) {
                    $todo[] = $src;
                }
            }
            if (empty($todo)) {
                continue;
            }
            $res = $api->translate_batch($todo, $lang, $default);
            if (is_wp_error($res) || !is_array($res)) {
                continue;
            }
            foreach ($todo as $src) {
                // translate_batch() NFC-normalizes texts and keys results by the
                // normalized source, so match on both the raw and normalized key.
                $t = null;
                if (isset($res[$src])) {
                    $t = $res[$src];
                } elseif (class_exists('Normalizer')) {
                    $nsrc = \Normalizer::normalize($src, \Normalizer::FORM_C);
                    if ($nsrc !== false && isset($res[$nsrc])) {
                        $t = $res[$nsrc];
                    }
                }
                // Final fallback: positional match if keys still didn't line up.
                if ($t === null) {
                    $vals = array_values($res);
                    $idx  = array_search($src, $todo, true);
                    if ($idx !== false && isset($vals[$idx])) {
                        $t = $vals[$idx];
                    }
                }
                if ($t && !is_wp_error($t) && trim((string) $t) !== '' && trim((string) $t) !== trim($src)) {
                    $map[$src][$lang] = (string) $t;
                    $filled++;
                }
            }
        }

        // Rebuild and save.
        $out = [];
        foreach ($map as $src => $trans) {
            $clean = array_filter(array_map('trim', (array) $trans));
            if (!empty($clean)) {
                $out[] = ['source' => $src, 'translations' => $clean];
            }
        }
        update_option('aitrfoel_string_translations', $out);

        wp_send_json_success([
            'filled'  => $filled,
            'strings' => count($out),
            'message' => sprintf('Translated %d values across %d strings.', $filled, count($out)),
        ]);
    }

    /**
     * Dry-run scan that reports which translatable forms / products were detected,
     * per plugin, so the user can see what "Auto-translate strings" will cover.
     * Counts only — does not call the translation API or write anything.
     */
    /**
     * Switch the default (unprefixed) language. POST execute=1 to perform the
     * migration; omit it for a dry-run preview of what would change.
     */
    public function handle_switch_default_language() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system is not active.');
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $new_default = isset($_POST['new_default']) ? sanitize_key(wp_unslash($_POST['new_default'])) : '';
        $execute     = !empty($_POST['execute']);

        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        $result  = $builtin->switch_default_language($new_default, !$execute);
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    public function handle_scan_forms() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $count_published = function ($post_type) {
            if (!post_type_exists($post_type)) {
                return null;
            }
            $ids = get_posts([
                'post_type'        => $post_type,
                'post_status'      => 'publish',
                'numberposts'      => -1,
                'fields'           => 'ids',
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);
            return is_array($ids) ? count($ids) : 0;
        };

        // Scope: 'forms' (form plugins only), 'filters' (JetSmartFilters only), or
        // 'all'. Lets the Contact Forms section report forms while the String
        // Translations section reports filters.
        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : 'all';

        $forms_counts = [
            'JetFormBuilder'  => ['count' => $count_published('jet-form-builder'),    'unit' => 'forms'],
            'WPForms'         => ['count' => $count_published('wpforms'),             'unit' => 'forms'],
            'Contact Form 7'  => ['count' => $count_published('wpcf7_contact_form'),  'unit' => 'forms'],
            'Gravity Forms'   => ['count' => class_exists('GFAPI') ? count((array) GFAPI::get_forms()) : null, 'unit' => 'forms'],
        ];
        $filter_counts = [
            'JetSmartFilters' => ['count' => $count_published('jet-smart-filters'),   'unit' => 'filters'],
        ];

        if ($scope === 'forms') {
            $form_counts = $forms_counts;
        } elseif ($scope === 'filters') {
            $form_counts = $filter_counts;
        } else {
            $form_counts = array_merge($forms_counts, $filter_counts);
        }

        // Actual translatable strings collected per plugin — same source the
        // Auto-translate button uses, so "found" and "translatable" always agree.
        $string_groups = self::collect_form_filter_sources();

        $groups = [];
        $parts  = [];
        foreach ($form_counts as $label => $info) {
            if ($info['count'] === null) {
                continue; // plugin not installed — omit entirely
            }
            $strings = isset($string_groups[$label])
                ? count(array_values(array_unique(array_filter($string_groups[$label]))))
                : 0;
            $groups[] = [
                'label'   => $label,
                'count'   => (int) $info['count'],
                'unit'    => $info['unit'],
                'strings' => $strings,
            ];
            $parts[] = sprintf('%s: %d %s → %d strings', $label, (int) $info['count'], $info['unit'], $strings);
        }

        if (empty($groups)) {
            wp_send_json_success([
                'groups'  => [],
                'message' => __('No supported forms or filters were found on this site.', 'ai-translator-for-elementor-and-polylang'),
            ]);
        }

        $message = sprintf(
            /* translators: %s: per-plugin list of detected forms and translatable strings. */
            __('Detected — %s. Click "Auto-translate strings" to translate them into every language.', 'ai-translator-for-elementor-and-polylang'),
            implode(' · ', $parts)
        );

        wp_send_json_success([
            'groups'  => $groups,
            'message' => $message,
        ]);
    }

    /**
     * Collect translatable UI strings from every supported form and filter plugin,
     * grouped by plugin label. Shared by the Auto-translate Strings tool and the
     * Scan report so their numbers can never drift apart.
     *
     * @return array<string,array<int,string>> [plugin label => list of source strings]
     */
    private static function collect_form_filter_sources() {
        $groups = [
            'JetFormBuilder'  => [],
            'WPForms'         => [],
            'Gravity Forms'   => [],
            'Contact Form 7'  => [],
            'JetSmartFilters' => [],
        ];

        // Statuses a form might legitimately be saved under (forms aren't always
        // "publish" — JetFormBuilder/WPForms can leave them as draft).
        $form_statuses = ['publish', 'draft', 'pending', 'private'];

        // --- JetFormBuilder: jet-forms/* block attributes in post_content ---
        if (post_type_exists('jet-form-builder')) {
            $ids = get_posts([
                'post_type'        => 'jet-form-builder',
                'post_status'      => $form_statuses,
                'numberposts'      => -1,
                'fields'           => 'ids',
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);
            foreach ($ids as $pid) {
                $content = get_post_field('post_content', $pid);
                if (!is_string($content) || trim($content) === '') {
                    continue;
                }
                // parse_blocks() is safe on any content; non-block markup yields a
                // single blockName=null node the collector skips.
                self::collect_jfb_block_strings(parse_blocks($content), $groups['JetFormBuilder']);
            }
        }

        // --- WPForms: JSON form config in post_content ---
        if (post_type_exists('wpforms') && function_exists('wpforms_decode')) {
            $ids = get_posts([
                'post_type'        => 'wpforms',
                'post_status'      => $form_statuses,
                'numberposts'      => -1,
                'fields'           => 'ids',
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);
            foreach ($ids as $pid) {
                $fd = wpforms_decode(get_post_field('post_content', $pid));
                if (!is_array($fd)) {
                    continue;
                }
                if (!empty($fd['fields']) && is_array($fd['fields'])) {
                    foreach ($fd['fields'] as $field) {
                        if (!is_array($field)) {
                            continue;
                        }
                        foreach (['label', 'description', 'placeholder'] as $k) {
                            if (!empty($field[$k]) && is_string($field[$k])) {
                                $groups['WPForms'][] = trim($field[$k]);
                            }
                        }
                        if (!empty($field['choices']) && is_array($field['choices'])) {
                            foreach ($field['choices'] as $choice) {
                                if (is_array($choice) && !empty($choice['label']) && is_string($choice['label'])) {
                                    $groups['WPForms'][] = trim($choice['label']);
                                }
                            }
                        }
                    }
                }
                if (!empty($fd['settings']) && is_array($fd['settings'])) {
                    foreach (['submit_text', 'submit_text_processing'] as $k) {
                        if (!empty($fd['settings'][$k]) && is_string($fd['settings'][$k])) {
                            $groups['WPForms'][] = trim($fd['settings'][$k]);
                        }
                    }
                    if (!empty($fd['settings']['confirmations']) && is_array($fd['settings']['confirmations'])) {
                        foreach ($fd['settings']['confirmations'] as $conf) {
                            if (is_array($conf) && !empty($conf['message']) && is_string($conf['message'])) {
                                $groups['WPForms'][] = trim(wp_strip_all_tags($conf['message']));
                            }
                        }
                    }
                }
            }
        }

        // --- Gravity Forms: forms live in GF custom tables (GFAPI) ---
        if (class_exists('GFAPI')) {
            $gf_forms = GFAPI::get_forms();
            if (is_array($gf_forms)) {
                foreach ($gf_forms as $form) {
                    if (!is_array($form)) {
                        continue;
                    }
                    if (!empty($form['fields']) && is_array($form['fields'])) {
                        foreach ($form['fields'] as $field) {
                            foreach (['label', 'description', 'placeholder'] as $k) {
                                $v = is_object($field) ? ($field->$k ?? '') : (is_array($field) ? ($field[$k] ?? '') : '');
                                if (!empty($v) && is_string($v)) {
                                    $groups['Gravity Forms'][] = trim($v);
                                }
                            }
                            $choices = is_object($field) ? ($field->choices ?? null) : (is_array($field) ? ($field['choices'] ?? null) : null);
                            if (!empty($choices) && is_array($choices)) {
                                foreach ($choices as $choice) {
                                    if (is_array($choice) && !empty($choice['text']) && is_string($choice['text'])) {
                                        $groups['Gravity Forms'][] = trim($choice['text']);
                                    }
                                }
                            }
                        }
                    }
                    if (!empty($form['button']['text']) && is_string($form['button']['text'])) {
                        $groups['Gravity Forms'][] = trim($form['button']['text']);
                    }
                    if (!empty($form['confirmations']) && is_array($form['confirmations'])) {
                        foreach ($form['confirmations'] as $conf) {
                            if (is_array($conf) && !empty($conf['message']) && is_string($conf['message'])) {
                                $groups['Gravity Forms'][] = trim(wp_strip_all_tags($conf['message']));
                            }
                        }
                    }
                }
            }
        }

        // --- Contact Form 7: label text from the form template + response messages ---
        if (post_type_exists('wpcf7_contact_form')) {
            $ids = get_posts([
                'post_type'        => 'wpcf7_contact_form',
                'post_status'      => $form_statuses,
                'numberposts'      => -1,
                'fields'           => 'ids',
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);
            foreach ($ids as $pid) {
                $form_tpl = get_post_meta($pid, '_form', true);
                if (is_string($form_tpl) && $form_tpl !== '') {
                    $stripped = preg_replace('/\[[^\]]*\]/', ' ', $form_tpl);
                    if (preg_match_all('/>([^<>]+)</', '>' . $stripped . '<', $m)) {
                        foreach ($m[1] as $frag) {
                            $frag = trim(html_entity_decode($frag, ENT_QUOTES));
                            if ($frag !== '' && preg_match('/\p{L}/u', $frag)) {
                                $groups['Contact Form 7'][] = $frag;
                            }
                        }
                    }
                }
                $messages = get_post_meta($pid, '_messages', true);
                if (is_array($messages)) {
                    foreach ($messages as $msg) {
                        if (is_string($msg) && trim($msg) !== '') {
                            $groups['Contact Form 7'][] = trim($msg);
                        }
                    }
                }
            }
        }

        // --- JetSmartFilters: "Show all" label, filter headings, manual options ---
        if (post_type_exists('jet-smart-filters')) {
            $ids = get_posts([
                'post_type'        => 'jet-smart-filters',
                'post_status'      => $form_statuses,
                'numberposts'      => -1,
                'fields'           => 'ids',
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);
            foreach ($ids as $pid) {
                foreach (['_all_option_label', '_color_image_add_all_option_lael', '_filter_label', '_dropdown_placeholder'] as $mk) {
                    $v = get_post_meta($pid, $mk, true);
                    if (is_string($v) && trim($v) !== '') {
                        $groups['JetSmartFilters'][] = trim($v);
                    }
                }
                $manual = get_post_meta($pid, '_source_manual_input', true);
                if (is_array($manual)) {
                    foreach ($manual as $opt) {
                        if (!empty($opt['label']) && is_string($opt['label'])) {
                            $groups['JetSmartFilters'][] = trim($opt['label']);
                        }
                    }
                }
                // For taxonomy-source filters, also collect the term labels so the
                // option list ("Logo", "Web design", …) translates via the string map
                // even for terms not yet translated through a post. The render hook
                // still prefers translated term meta when it exists.
                if (get_post_meta($pid, '_data_source', true) === 'taxonomies') {
                    $tax = get_post_meta($pid, '_source_taxonomy', true);
                    if (is_string($tax) && $tax !== '' && taxonomy_exists($tax)) {
                        $terms = get_terms([
                            'taxonomy'   => $tax,
                            'hide_empty' => false,
                        ]);
                        if (is_array($terms)) {
                            foreach ($terms as $term) {
                                if (is_object($term) && !empty($term->name) && is_string($term->name)) {
                                    $groups['JetSmartFilters'][] = trim($term->name);
                                }
                            }
                        }
                    }
                }
            }
        }

        return $groups;
    }

    /**
     * Recursively collect user-facing strings from JetFormBuilder (jet-forms/*)
     * blocks: field labels, descriptions, placeholders, option labels, and button
     * text. Appends to $sources by reference.
     *
     * @param array $blocks  Parsed blocks (from parse_blocks()).
     * @param array $sources Source string accumulator (by reference).
     */
    private static function collect_jfb_block_strings($blocks, &$sources) {
        if (!is_array($blocks)) {
            return;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $name  = isset($block['blockName']) ? (string) $block['blockName'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];

            if ($name !== '' && strpos($name, 'jet-forms/') === 0) {
                foreach (['label', 'desc', 'placeholder', 'add_more_label', 'prev_label', 'next_label'] as $k) {
                    if (!empty($attrs[$k]) && is_string($attrs[$k])) {
                        $sources[] = trim($attrs[$k]);
                    }
                }
                if (!empty($attrs['field_options']) && is_array($attrs['field_options'])) {
                    foreach ($attrs['field_options'] as $opt) {
                        if (is_array($opt) && !empty($opt['label']) && is_string($opt['label'])) {
                            $sources[] = trim($opt['label']);
                        }
                    }
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collect_jfb_block_strings($block['innerBlocks'], $sources);
            }
        }
    }

    /**
     * Reconcile inconsistent translation links. Supports a dry-run "scan" (POST
     * scan=1) that reports what would change without writing.
     */
    public function handle_repair_links() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        $apply  = empty($_POST['scan']); // scan=1 => dry run
        $report = AITRFOEL_Builtin_Integration::get_instance()->repair_translation_links($apply);

        wp_send_json_success($report);
    }

    /**
     * Re-localize internal links on all translated pages.
     */
    public function handle_repair_content_links() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        $apply  = empty($_POST['scan']); // scan=1 => dry run
        $report = AITRFOEL_Builtin_Integration::get_instance()->repair_content_links($apply);

        wp_send_json_success($report);
    }

    /**
     * Check if user is eligible to see review prompt.
     * Shows after 3+ successful translations and not dismissed.
     */
    private function is_review_eligible() {
        if (get_option('aitrfoel_review_dismissed')) {
            return false;
        }
        $count = (int) get_option('aitrfoel_successful_translations_count', 0);
        return $count >= 3;
    }

    /**
     * Increment successful translation counter.
     */
    private function increment_translation_counter() {
        $count = (int) get_option('aitrfoel_successful_translations_count', 0);
        update_option('aitrfoel_successful_translations_count', $count + 1);
    }

    /**
     * Handle dismiss review request.
     */
    public function handle_dismiss_review() {
        // Accept nonce from either the main page or translate-all page
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'aitrfoel_ajax_nonce') &&
            !wp_verify_nonce($_POST['nonce'] ?? '', 'aitrfoel_translate_all_nonce')) {
            wp_send_json_error('Invalid nonce.');
        }
        
        $mode = sanitize_text_field($_POST['mode'] ?? 'dismiss');
        if ($mode === 'snooze') {
            // Snooze for 7 days
            update_option('aitrfoel_review_snoozed_until', time() + (7 * DAY_IN_SECONDS));
        } else {
            // Permanent dismissal ('done' or 'dismiss')
            update_option('aitrfoel_review_dismissed', true);
        }
        wp_send_json_success();
    }

    /**
     * Handle dismiss capacity/upgrade banner.
     */
    public function handle_dismiss_upgrade_banner() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        update_option('aitrfoel_upgrade_banner_dismissed', true);
        wp_send_json_success();
    }

    /**
     * Increment translation success counter and check review eligibility.
     */
    public function handle_record_translation_success() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        
        $count = (int) get_option('aitrfoel_successful_translations_count', 0);
        $count++;
        update_option('aitrfoel_successful_translations_count', $count);
        
        $review_dismissed = get_option('aitrfoel_review_dismissed', false);
        $snoozed_until    = (int) get_option('aitrfoel_review_snoozed_until', 0);
        
        // Ask for a review only after real use: 3+ successful jobs AND 3+ days since activation,
        // so a first batch (which can be 10 jobs at once) doesn't trigger it before the user has checked the result.
        $activated_at = strtotime((string) get_option('aitrfoel_license_activated_at', '')) ?: time();
        $show_review = (!$review_dismissed && time() > $snoozed_until && $count >= 3 && (time() - $activated_at) >= 3 * DAY_IN_SECONDS);
        
        wp_send_json_success([
            'success_count' => $count,
            'show_review'   => $show_review
        ]);
    }
    
    /**
     * Get pages list for translation
     */
    public function get_pages() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $args = [
                'post_type'      => ['page', 'post'],
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ];
            
            $pages = get_posts($args);
            
            if (empty($pages)) {
                wp_send_json_error(__('No published pages or posts found.', 'ai-translator-for-elementor-and-polylang'));
                return;
            }
            
            $pages_data = [];
            foreach ($pages as $page) {
                $pages_data[] = [
                    'id' => $page->ID,
                    'title' => $page->post_title,
                    'url' => get_permalink($page->ID),
                    'edit_url' => admin_url('post.php?post=' . $page->ID . '&action=edit')
                ];
            }
            
            wp_send_json_success($pages_data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Estimate translation cost
     */
    public function estimate_cost() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Invalid page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get translatable content
            $parser_class = class_exists('aitrfoel_Widget_Parser_Fixed') ? 'aitrfoel_Widget_Parser_Fixed' : 'AITRFOEL_Widget_Parser';
            $parser = $parser_class::get_instance();
            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
            
            if (empty($elementor_data)) {
                throw new Exception(__('No Elementor data found for this page.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $elements = json_decode($elementor_data, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception(__('Invalid Elementor data format.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Extract translatable strings
            $translatable_strings = [];
            $translation_map = $parser->parse_for_translation($elements);
            $translatable_strings = array_values($translation_map);
            
            if (empty($translatable_strings)) {
                wp_send_json_success([
                    'message' => __('No translatable content found.', 'ai-translator-for-elementor-and-polylang'),
                    'cost' => '$0.00',
                    'details' => []
                ]);
                return;
            }
            
            // Calculate basic word and character counts
            $word_count = 0;
            foreach ($translatable_strings as $string) {
                $word_count += AITRFOEL_Translation_Engine::count_words($string);
            }
            
            $character_count = array_sum(array_map('strlen', $translatable_strings));
            
            // Check word limits for estimation
            try {
                $limit_info = $this->check_word_limit($word_count);
            } catch (Exception $limit_error) {
                // If word limit exceeded, return error instead of estimate
                throw $limit_error;
            }

            // Add breakdown by content type
            $breakdown = [
                'total_elements' => count($translatable_strings),
                'word_count' => $word_count,
                'character_count' => $character_count,
                'words_left' => $limit_info['words_left'],
                'word_limit' => $limit_info['limit'],
                'license_type' => $limit_info['license'],
                'provider' => 'openai',
                'model' => get_option('aitrfoel_openai_model', 'gpt-3.5-turbo')
            ];

            wp_send_json_success([
                'cost' => 'Estimate not available',
                'cost_raw' => 0,
                'details' => [],
                'breakdown' => $breakdown,
                'message' => sprintf(
                    /* translators: 1: Number of words, 2: Number of elements */
                    __('Word count: %1$d words (%2$d elements)', 'ai-translator-for-elementor-and-polylang'),
                    $word_count,
                    count($translatable_strings)
                )
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Translate page with enhanced error handling
     */
    public function translate_page() {
        // Extend PHP execution limit to match the MeepTech API timeout so large pages
        // do not get killed by the server's default max_execution_time (commonly 30s).
        // @ suppresses the warning on hosts where set_time_limit is in disable_functions.
        @set_time_limit(300);
        // Ensure PHP finishes writing the translation even if the browser disconnects before the response.
        ignore_user_abort(true);

        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get parameters
            $page_id = intval($_POST['page_id'] ?? 0);
            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            $source_lang = sanitize_text_field($_POST['source_lang'] ?? 'auto');
            
            // Sanitize strings array
            $strings_to_translate = [];
            if (isset($_POST['strings']) && is_array($_POST['strings'])) {
                foreach ($_POST['strings'] as $string) {
                    // Use wp_kses_post to allow safe HTML in translation strings
                    $strings_to_translate[] = wp_kses_post(wp_unslash($string));
                }
            }
            
            // Validate inputs
            if (!$page_id) {
                throw new Exception(__('Invalid page ID.', 'ai-translator-for-elementor-and-polylang'));
            }

            // The generic check above only says the user may edit *some* posts.
            // Reading or translating this page requires edit rights on it.
            if (!current_user_can('edit_post', $page_id)) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // If no strings provided, fetch content based on content type
            if (empty($strings_to_translate)) {
                // Detect content type
                $content_detector = AITRFOEL_Content_Detector::get_instance();
                $content_type = $content_detector->detect($page_id);
                
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            // Elementor pages
                            $parser_class = class_exists('aitrfoel_Widget_Parser_Fixed') ? 'aitrfoel_Widget_Parser_Fixed' : 'AITRFOEL_Widget_Parser';
                            if (!class_exists($parser_class)) {
                                throw new Exception(__('Widget parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $parser = $parser_class::get_instance();
                            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                            
                            if (empty($elementor_data)) {
                                throw new Exception(__('No Elementor data found.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            
                            $elementor_data_array = json_decode($elementor_data, true);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception(__('Failed to decode Elementor data.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            
                            $translation_map = $parser->parse_for_translation($elementor_data_array);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            // Gutenberg (Block Editor) pages
                            if (!class_exists('AITRFOEL_Gutenberg_Parser')) {
                                throw new Exception(__('Gutenberg parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                            $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            // Classic Editor pages
                            if (!class_exists('AITRFOEL_Classic_Parser')) {
                                throw new Exception(__('Classic parser not available.', 'ai-translator-for-elementor-and-polylang'));
                            }
                            $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                            $translation_map = $classic_parser->parse_for_translation($page_id);
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_WOOCOMMERCE:
                            // WooCommerce product with no editor content
                            $wc_post = get_post($page_id);
                            if ($wc_post) {
                                if (!empty($wc_post->post_title)) {
                                    $translation_map['wc_title'] = [
                                        'original' => $wc_post->post_title,
                                        'path' => 'title',
                                        'type' => 'text',
                                        'widget_type' => 'woocommerce_product',
                                        'display_text' => $wc_post->post_title,
                                    ];
                                }
                                if (!empty(trim($wc_post->post_excerpt))) {
                                    $translation_map['wc_excerpt'] = [
                                        'original' => $wc_post->post_excerpt,
                                        'path' => 'excerpt',
                                        'type' => 'text',
                                        'widget_type' => 'woocommerce_product',
                                        'display_text' => wp_trim_words($wc_post->post_excerpt, 20),
                                    ];
                                }
                            }
                            break;
                            
                        default:
                            throw new Exception(__('This page does not contain any translatable content. Please ensure the page has content created with Elementor, Gutenberg (Block Editor), or Classic Editor.', 'ai-translator-for-elementor-and-polylang'));
                    }
                } catch (Exception $parse_error) {
                    throw new Exception($parse_error->getMessage());
                }
                
                if (empty($translation_map)) {
                    throw new Exception(__('No translatable content found on this page.', 'ai-translator-for-elementor-and-polylang'));
                }
                
                wp_send_json_success(['strings' => array_values($translation_map), 'content_type' => $content_type]);
                return;
            }
            
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Check API configuration
            $api_key = get_option('aitrfoel_openai_api_key', '');
                
            if (empty($api_key)) {
                throw new Exception(__('Please configure your OpenAI API key in the settings.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Estimate word count for limit validation
            // Apply server usage factor (server counts both source + translated words)
            $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 1;
            $estimated_words = 0;
            foreach ($strings_to_translate as $string) {
                $estimated_words += AITRFOEL_Translation_Engine::count_words($string);
            }
            $estimated_words *= $server_factor;
            
            // Check word limits before proceeding - WITH DEBUGGING
            
            try {
                $this->check_word_limit($estimated_words);
                
            } catch (Exception $e) {
                
                throw $e; // Re-throw to be handled by outer catch
            }
            
            // Start translation process
            $engine = AITRFOEL_Translation_Engine::get_instance();
            
            // Log the translation start
            AITRFOEL_Logger::log(sprintf(
                'Starting translation: Page %d to %s using %s',
                $page_id,
                $target_lang,
                'openai'
            ), 'info');
            
            // Perform translation with error handling
            $start_time = microtime(true);
            
            try {
                $new_page_id = $engine->translate_page($page_id, $target_lang, $source_lang, $strings_to_translate);
                
                if (is_wp_error($new_page_id)) {
                    throw new Exception($new_page_id->get_error_message());
                }
            } catch (Exception $translation_error) {
                // Log the specific translation error
                AITRFOEL_Logger::log('Translation failed: ' . $translation_error->getMessage(), 'error', [
                    'page_id' => $page_id,
                    'target_lang' => $target_lang,
                    'provider' => 'openai'
                ]);
                
                // Provide user-friendly error message based on error type
                $error_message = $this->get_user_friendly_error($translation_error);
                throw new Exception($error_message);
            }
            
            $end_time = microtime(true);
            $translation_time = round($end_time - $start_time, 2);
            
            if (!$new_page_id) {
                throw new Exception(__('Translation failed. Please check the logs for details.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get translation statistics
            $new_page = get_post($new_page_id);
            $stats = $engine->get_last_translation_stats();
            
            // Record basic usage statistics
            $usage_tracker = AITRFOEL_Usage_Tracker::get_instance();
            $usage_tracker->record_usage([
                'word_count' => $stats['word_count'] ?? 0,
                'page_count' => 1,
                'character_count' => $stats['character_count'] ?? 0
            ]);

            // Increment successful translation counter for review prompt
            $this->increment_translation_counter();
            
            // Log success
            AITRFOEL_Logger::log(sprintf(
                'Translation completed: Page %d -> %d in %s seconds',
                $page_id,
                $new_page_id,
                $translation_time
            ), 'success');
            
            // Prepare response
            $response_data = [
                'success' => true,
                'new_page_id' => $new_page_id,
                'translated_page_id' => $new_page_id,
                'original_page_id' => $page_id,
                'original_url' => get_permalink($page_id),
                'new_page_url' => get_permalink($new_page_id),
                'new_view_url' => get_permalink($new_page_id),
                'view_url' => get_permalink($new_page_id),
                'edit_url' => admin_url('post.php?post=' . $new_page_id . '&action=edit'),
                'preview_url' => get_preview_post_link($new_page_id),
                'original_title' => get_the_title($page_id),
                'title' => $new_page->post_title,
                'translated_title' => $new_page->post_title,
                'target_language' => $target_lang,
                'status' => $new_page->post_status,
                'is_draft' => $new_page->post_status === 'draft',
                'statistics' => [
                    'time_taken' => $translation_time . 's',
                    'elements_translated' => $stats['elements_translated'] ?? 0,
                    'words_translated' => $stats['word_count'] ?? 0,
                    'cost' => '$' . number_format($stats['estimated_cost'] ?? 0, 4)
                ],
                'message' => sprintf(
                    /* translators: 1: Number of words translated, 2: Time in seconds */
                    __('Translation completed successfully! %1$d words translated in %2$s seconds.', 'ai-translator-for-elementor-and-polylang'),
                    $stats['word_count'] ?? 0,
                    $translation_time
                )
            ];
            
            // Add review eligibility flag
            $response_data['review_eligible'] = $this->is_review_eligible();

            wp_send_json_success($response_data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get basic usage statistics
     */
    public function get_usage_stats() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to view statistics.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $usage_tracker = AITRFOEL_Usage_Tracker::get_instance();
            $month = sanitize_text_field($_POST['month'] ?? gmdate('Y-m'));

            // Get simplified monthly stats
            $stats = $usage_tracker->get_monthly_stats($month);

            // Return simplified response structure
            wp_send_json_success([
                'stats' => [
                    'total_words' => $stats['total_words'],
                    'total_pages' => $stats['total_pages'], 
                    'total_characters' => $stats['total_characters'],
                    'month' => $stats['month']
                ],
                'breakdown' => [] // No breakdown in simplified version
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Clear translation cache
     */
    public function clear_cache() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to clear cache.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $cache_manager = AITRFOEL_Cache_Manager::get_instance();
            $cleared = $cache_manager->clear_cache();
            
            if (class_exists('AITRFOEL_Translation_Memory')) {
                AITRFOEL_Translation_Memory::get_instance()->clear_memory();
            }
            
            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %d: Number of cache entries removed */
                    __('Cache and Translation Memory cleared successfully! %d entries removed.', 'ai-translator-for-elementor-and-polylang'),
                    $cleared
                )
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get cache statistics
     */
    public function get_cache_stats() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to view cache statistics.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $cache_manager = AITRFOEL_Cache_Manager::get_instance();
            $stats = $cache_manager->get_stats();
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Handle publish translation request (legacy support)
     */
    public function handle_publish_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Missing page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception(__('Page not found.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!current_user_can('publish_post', $page_id) && !current_user_can('edit_post', $page_id)) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            $pto = get_post_type_object($page->post_type);
            if (!$pto || !current_user_can($pto->cap->publish_posts)) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $result = wp_update_post([
                'ID' => $page_id,
                'post_status' => 'publish'
            ]);
            
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }
            
            AITRFOEL_Logger::log(sprintf('Published translated page: %s (ID: %d)', $page->post_title, $page_id), 'success');
            
            wp_send_json_success([
                /* translators: %s: Page title */
                'message' => sprintf(__('Page "%s" has been published successfully!', 'ai-translator-for-elementor-and-polylang'), $page->post_title),
                'page_id' => $page_id,
                'view_url' => get_permalink($page_id),
                'edit_url' => get_edit_post_link($page_id, 'raw'),
                'status' => 'publish'
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Handle get preview URL request (legacy support)
     */
    public function handle_get_preview_url() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_posts')) {
                throw new Exception(__('Permission denied.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page_id = intval($_POST['page_id'] ?? 0);
            
            if (!$page_id) {
                throw new Exception(__('Missing page ID.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception(__('Page not found.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            wp_send_json_success([
                'preview_url' => get_preview_post_link($page_id),
                'edit_url' => get_edit_post_link($page_id, 'raw'),
                'title' => $page->post_title,
                'status' => $page->post_status
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get user-friendly error message
     */
    private function get_user_friendly_error($exception) {
        $message = $exception->getMessage();
        
        // API key errors
        if (strpos($message, 'API key') !== false || strpos($message, 'Invalid API') !== false) {
            return __('API key is invalid or missing. Please check your settings.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Rate limit errors
        if (strpos($message, 'rate limit') !== false || strpos($message, '429') !== false) {
            return __('API rate limit exceeded. Please wait a moment and try again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Quota errors
        if (strpos($message, 'quota') !== false || strpos($message, 'billing') !== false) {
            return __('API quota exceeded or billing issue. Please check your API account.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Network errors
        if (strpos($message, 'cURL') !== false || strpos($message, 'connection') !== false) {
            return __('Network connection error. Please check your internet connection and try again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Timeout errors
        if (strpos($message, 'timeout') !== false || strpos($message, 'timed out') !== false) {
            return __('Request timed out. The page might be too large. Try translating smaller sections.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // JSON errors
        if (strpos($message, 'JSON') !== false) {
            return __('Data format error. Please try refreshing the page and trying again.', 'ai-translator-for-elementor-and-polylang');
        }
        
        // Default
        return sprintf(
            /* translators: %s: Error message */
            __('Translation error: %s', 'ai-translator-for-elementor-and-polylang'),
            $message
        );
    }
    
    /**
     * Handle errors consistently
     */
    private function handle_error($exception) {
        $error_message = $exception->getMessage();
        
        // Check if message is JSON-encoded error data (from word limit checks)
        $error_data = json_decode($error_message, true);
        
        if ($error_data && isset($error_data['type'])) {
            // This is a structured error (limit_reached, cooldown, insufficient_words)
            // Log the error
            AITRFOEL_Logger::log('AJAX Error: ' . $error_data['type'] . ' - ' . $error_data['message'], 'error', $error_data);
            
            // Send structured error response
            wp_send_json_error($error_data);
        } else {
            // Regular error handling
            // Log the error
            AITRFOEL_Logger::log('AJAX Error: ' . $error_message, 'error', [
                'trace' => $exception->getTraceAsString(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            
            // Send user-friendly error response
            wp_send_json_error([
                'message' => $error_message,
                'code' => $exception->getCode(),
                'technical_details' => WP_DEBUG ? [
                    'file' => basename($exception->getFile()),
                    'line' => $exception->getLine()
                ] : null
            ]);
        }
    }

    // =============================================
    // BULK TRANSLATION ENDPOINTS
    // =============================================

    /**
     * Pre-flight analysis for bulk translation
     * Accurately counts words BEFORE starting translation
     * 
     * @since 1.0.3
     */
    public function handle_pre_analyze_bulk() {
        // Enable error logging for debugging
        
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Handle pages array
            $page_ids = [];
            if (isset($_POST['pages']) && is_array($_POST['pages'])) {
                $page_ids = array_map('intval', $_POST['pages']);
            } elseif (isset($_POST['pages'])) {
                $pages_str = sanitize_text_field($_POST['pages']);
                $page_ids = !empty($pages_str) ? array_map('intval', explode(',', $pages_str)) : [];
            }

            // Handle languages array  
            $target_langs = [];
            if (isset($_POST['languages']) && is_array($_POST['languages'])) {
                $target_langs = array_map('sanitize_text_field', $_POST['languages']);
            } elseif (isset($_POST['languages'])) {
                $langs_str = sanitize_text_field($_POST['languages']);
                $target_langs = !empty($langs_str) ? array_map('sanitize_text_field', explode(',', $langs_str)) : [];
            }

            if (empty($page_ids) || empty($target_langs)) {
                throw new Exception(__('Pages and languages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Check if parser classes exist
            if (!class_exists('AITRFOEL_Content_Detector')) {
                throw new Exception(__('Content detector class not loaded. Please refresh the page.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get content detector
            $content_detector = AITRFOEL_Content_Detector::get_instance();
            
            // Analyze each page
            $breakdown = [];
            $total_words = 0;
            $queue_preview = [];
            
            foreach ($page_ids as $page_id) {
                
                // Validate page exists
                $post = get_post($page_id);
                if (!$post) {
                    /* translators: %d: Page ID */
                    throw new Exception(sprintf(__('Page ID %d not found.', 'ai-translator-for-elementor-and-polylang'), $page_id));
                }
                
                // Detect content type and get appropriate parser
                $content_type = $content_detector->detect($page_id);
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            if (class_exists('AITRFOEL_Widget_Parser')) {
                                $parser = AITRFOEL_Widget_Parser::get_instance();
                                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                                
                                if (!empty($elementor_data)) {
                                    $elementor_data_array = json_decode($elementor_data, true);
                                    
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $translation_map = $parser->parse_for_translation($elementor_data_array);
                                    } else {
                                        AITRFOEL_Logger::log("Failed to decode Elementor data for page {$page_id}: " . json_last_error_msg(), 'warning');
                                    }
                                }
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                                $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                                $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            if (class_exists('AITRFOEL_Classic_Parser')) {
                                $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                                $translation_map = $classic_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        default:
                            // Skip unsupported content types
                            AITRFOEL_Logger::log("Skipping page {$page_id}: unsupported content type {$content_type}", 'info');
                            continue 2; // Continue to next page
                    }
                } catch (Exception $e) {
                    AITRFOEL_Logger::log("Error parsing page {$page_id}: " . $e->getMessage(), 'warning', $page_id);
                    continue;
                }
                
                if (empty($translation_map)) {
                    // Skip pages with no translatable content
                    AITRFOEL_Logger::log("Skipping page {$page_id}: no translatable content found", 'info');
                    continue;
                }
                
                // Count words accurately
                $page_word_count = 0;
                foreach ($translation_map as $item) {
                    if (isset($item['original']) && is_string($item['original'])) {
                        $page_word_count += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($item['original']));
                    }
                }
                
                // Calculate total for this page across all languages
                // Apply server usage factor (server counts both source + translated words)
                $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 1;
                $page_total_words = $page_word_count * count($target_langs) * $server_factor;
                $total_words += $page_total_words;
                
                // Add to breakdown
                $breakdown[] = [
                    'page_id' => $page_id,
                    'page_title' => $post->post_title,
                    'words' => $page_word_count,
                    'languages' => $target_langs,
                    'total_words' => $page_total_words
                ];
                
                // Generate queue preview
                foreach ($target_langs as $lang) {
                    $queue_preview[] = [
                        'label' => sprintf('%s → %s', $post->post_title, strtoupper($lang)),
                        'words' => $page_word_count,
                        'page_id' => $page_id,
                        'language' => $lang
                    ];
                }
            }
            
            // Get license status and word limit
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            $license_status = $license_manager->get_license_status();
            
            $words_used = $license_status['words_used'];
            $word_limit = $license_status['word_limit'];
            $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
            $plan_name = $license_status['plan'];
            
            // Check if can proceed (unlimited plans always proceed)
            $can_proceed = ($word_limit === -1) || ($total_words <= $words_left);
            
            // Prepare response
            $response = [
                'can_proceed' => $can_proceed,
                'total_words_needed' => $total_words,
                'words_left' => $words_left,
                'word_limit' => $word_limit,
                'words_used' => $words_used,
                'plan' => $plan_name,
                'breakdown' => $breakdown,
                'queue_preview' => $queue_preview,
                'total_jobs' => count($queue_preview),
                'pages_count' => count($page_ids),
                'languages_count' => count($target_langs)
            ];
            
            // Add error message if cannot proceed
            if (!$can_proceed) {
                $response['error'] = sprintf(
                    /* translators: 1: Number of words required, 2: Number of words remaining, 3: Plan name */
                    __('Insufficient words available. This translation requires %1$s words, but you only have %2$s words left in your %3$s plan.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($total_words),
                    number_format($words_left),
                    $plan_name
                );
                
                // Add upgrade URL if available
                if (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) {
                    $response['upgrade_url'] = eatp_fs()->get_upgrade_url();
                } else {
                    $response['upgrade_url'] = 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade';
                }
            }
            
            AITRFOEL_Logger::log(sprintf(
                'Pre-analysis completed: %d pages × %d languages = %d words needed, %d words available, can_proceed: %s',
                count($page_ids),
                count($target_langs),
                $total_words,
                $words_left,
                $can_proceed ? 'YES' : 'NO'
            ), 'info');
            wp_send_json_success($response);

        } catch (Exception $e) {
            error_log('EAT: Exception in pre-analysis: ' . $e->getMessage());
            error_log('EAT: Exception trace: ' . $e->getTraceAsString());
            $this->handle_error($e);
        } catch (Error $e) {
            error_log('EAT: Fatal error in pre-analysis: ' . $e->getMessage());
            error_log('EAT: Error trace: ' . $e->getTraceAsString());
            wp_send_json_error([
                'message' => 'Fatal error: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }

    /**
     * Start bulk translation process
     */
    public function handle_start_bulk_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Handle pages array
            $page_ids = [];
            if (isset($_POST['pages']) && is_array($_POST['pages'])) {
                $page_ids = array_map('intval', $_POST['pages']);
            } elseif (isset($_POST['pages'])) {
                $pages_str = sanitize_text_field($_POST['pages']);
                $page_ids = !empty($pages_str) ? array_map('intval', explode(',', $pages_str)) : [];
            }

            // Handle languages array  
            $target_langs = [];
            if (isset($_POST['languages']) && is_array($_POST['languages'])) {
                $target_langs = array_map('sanitize_text_field', $_POST['languages']);
            } elseif (isset($_POST['languages'])) {
                $langs_str = sanitize_text_field($_POST['languages']);
                $target_langs = !empty($langs_str) ? array_map('sanitize_text_field', explode(',', $langs_str)) : [];
            }

            $source_language = sanitize_text_field($_POST['source_language'] ?? 'auto');

            if (empty($page_ids) || empty($target_langs)) {
                throw new Exception(__('Pages and languages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Content_Detector')) {
                throw new Exception(__('Content detector is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Bulk_Translation_Queue')) {
                throw new Exception(__('Bulk translation queue is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Translation_Engine')) {
                throw new Exception(__('Translation engine is not available. Please reload the page and try again.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Validate page IDs
            foreach ($page_ids as $page_id) {
                if (!get_post($page_id)) {
                    /* translators: %d: Page ID */
                    throw new Exception(sprintf(__('Page ID %d not found.', 'ai-translator-for-elementor-and-polylang'), $page_id));
                }
            }

            // ENHANCED: Accurate word count using appropriate parser for each content type
            $content_detector = AITRFOEL_Content_Detector::get_instance();
            $total_estimated_words = 0;
            
            foreach ($page_ids as $page_id) {
                $content_type = $content_detector->detect($page_id);
                $translation_map = [];
                
                try {
                    switch ($content_type) {
                        case AITRFOEL_Content_Detector::TYPE_ELEMENTOR:
                            if (class_exists('AITRFOEL_Widget_Parser')) {
                                $parser = AITRFOEL_Widget_Parser::get_instance();
                                $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                                
                                if (!empty($elementor_data)) {
                                    $elementor_data_array = json_decode($elementor_data, true);
                                    
                                    if (json_last_error() === JSON_ERROR_NONE) {
                                        $translation_map = $parser->parse_for_translation($elementor_data_array);
                                    }
                                }
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_GUTENBERG:
                            if (class_exists('AITRFOEL_Gutenberg_Parser')) {
                                $gutenberg_parser = AITRFOEL_Gutenberg_Parser::get_instance();
                                $translation_map = $gutenberg_parser->parse_for_translation($page_id);
                            }
                            break;
                            
                        case AITRFOEL_Content_Detector::TYPE_CLASSIC:
                            if (class_exists('AITRFOEL_Classic_Parser')) {
                                $classic_parser = AITRFOEL_Classic_Parser::get_instance();
                                $translation_map = $classic_parser->parse_for_translation($page_id);
                            }
                            break;
                    }
                } catch (Throwable $e) {
                    AITRFOEL_Logger::log("Error parsing page {$page_id}: " . $e->getMessage(), 'warning', $page_id);
                    continue;
                }
                
                foreach ($translation_map as $item) {
                    if (isset($item['original']) && is_string($item['original'])) {
                        $total_estimated_words += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($item['original']));
                    }
                }
            }
            
            // Multiply by number of languages and apply server usage factor
            // Server counts both source + translated words, so multiply by factor
            $server_factor = defined('AITRFOEL_Translate_All::SERVER_USAGE_FACTOR') ? AITRFOEL_Translate_All::SERVER_USAGE_FACTOR : 1;
            $total_estimated_words *= count($target_langs) * $server_factor;
            
            AITRFOEL_Logger::log(sprintf(
                'Bulk translation pre-flight check: %d pages × %d languages × %d factor = %d estimated words',
                count($page_ids),
                count($target_langs),
                $server_factor,
                $total_estimated_words
            ), 'info');
            
            // Check word limit BEFORE starting translation
            try {
                $limit_check = $this->check_word_limit($total_estimated_words);
                
                AITRFOEL_Logger::log(sprintf(
                    'Word limit check passed: %d words needed, %d words available',
                    $total_estimated_words,
                    $limit_check['words_left']
                ), 'info');
                
            } catch (Exception $e) {
                // Re-throw the exception to be caught by the outer try/catch which handles errors properly
                throw $e;
            }

            // Create job ID
            $job_id = 'bulk_' . time() . '_' . wp_generate_password(8, false);

            // Create job in database using bulk translation queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();

            $total_jobs = count($page_ids) * count($target_langs);

            $job_data = [
                'page_ids' => $page_ids,
                'target_languages' => $target_langs,
                'source_language' => $source_language,
                'user_id' => get_current_user_id(),
                'total_items' => $total_jobs,
            ];

            $db_job_id = $queue->create_job($job_id, $job_data);

            if (is_wp_error($db_job_id)) {
                throw new Exception($db_job_id->get_error_message());
            }
            
            // Update job status to processing
            $queue->update_job($job_id, [
                'status' => 'processing',
                'completed_items' => 0,
                'total_items' => $total_jobs,
                'current_step' => 'Initializing translation process...',
                'details' => 'Preparing pages for translation'
            ]);

            do_action('aitrfoel_before_bulk_translation', $job_id, $total_jobs);

            // Build the flat item queue — JS processes one item per AJAX call to avoid 504 timeouts.
            $items = [];
            foreach ($page_ids as $page_id) {
                foreach ($target_langs as $lang) {
                    $items[] = ['page_id' => $page_id, 'lang' => $lang];
                }
            }

            wp_send_json_success([
                'job_id'          => $job_id,
                'items'           => $items,
                'total'           => $total_jobs,
                'source_language' => $source_language,
                'status'          => 'ready',
                'message'         => sprintf(
                    /* translators: 1: Number of pages, 2: Number of target languages */
                    __('Bulk translation started for %1$d pages to %2$d languages.', 'ai-translator-for-elementor-and-polylang'),
                    count($page_ids),
                    count($target_langs)
                ),
                'pages_count'     => count($page_ids),
                'languages_count' => count($target_langs),
                'total_jobs'      => $total_jobs,
            ]);

        } catch (Throwable $e) {
            AITRFOEL_Logger::log('Bulk translation fatal error: ' . $e->getMessage(), 'error', [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            $this->handle_error($e);
        }
    }

    /**
     * Process a single bulk translation item (one page × one language).
     *
     * The JS side calls this once per item so each PHP request is short-lived,
     * avoiding 504 Gateway Timeout errors that happen when all items are processed
     * in one long-running request.
     */
    public function handle_process_bulk_item() {
        // Match PHP execution limit to the wp_remote_post OpenAI timeout (300s) so PHP can't die before the HTTP call does.
        // @ suppresses the warning on hosts where set_time_limit is in disable_functions (same pattern as WP core wp-cron.php).
        @set_time_limit(300);
        // Ensure PHP finishes writing the translation even if the browser disconnects before the response.
        ignore_user_abort(true);

        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if ( ! current_user_can('edit_pages') ) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            $job_id           = sanitize_text_field($_POST['job_id']        ?? '');
            $page_id          = intval(             $_POST['page_id']       ?? 0);
            $target_lang      = sanitize_text_field($_POST['target_lang']   ?? '');
            $item_index       = intval(             $_POST['item_index']    ?? 0);  // 0-based
            $total_items      = max(1, intval(      $_POST['total_items']   ?? 1));
            $completed_so_far = intval(             $_POST['completed_so_far'] ?? 0);
            $error_count      = intval(             $_POST['error_count']   ?? 0);
            $source_lang      = sanitize_text_field($_POST['source_language'] ?? 'auto');

            if (empty($job_id) || empty($page_id) || empty($target_lang)) {
                throw new Exception(__('Missing required parameters.', 'ai-translator-for-elementor-and-polylang'));
            }

            $queue      = AITRFOEL_Bulk_Translation_Queue::get_instance();
            $engine     = AITRFOEL_Translation_Engine::get_instance();
            $page_title = get_the_title($page_id);
            $is_last    = ($item_index + 1 >= $total_items);

            // Per-item word limit check (non-fatal on exception)
            try {
                $license_manager = AITRFOEL_License_Manager_Server::get_instance();
                $license_status  = $license_manager->get_license_status();
                $words_left      = $license_status['word_limit'] === -1
                    ? -1
                    : max(0, $license_status['word_limit'] - $license_status['words_used']);

                if ($license_status['word_limit'] !== -1 && $words_left <= 0) {
                    $queue->update_job($job_id, [
                        'status'          => 'paused',
                        'completed_items' => $completed_so_far,
                        'current_step'    => 'Paused: Word limit reached',
                        'error_type'      => 'limit_reached_during_process',
                    ]);
                    wp_send_json_success([
                        'status'    => 'limit_reached',
                        'completed' => $completed_so_far,
                        'total'     => $total_items,
                    ]);
                    return;
                }
            } catch (Throwable $e) {
                AITRFOEL_Logger::log('Limit check error (non-fatal): ' . $e->getMessage(), 'warning');
            }

            // Mark this item as in-progress in DB
            $queue->update_job($job_id, [
                'progress'         => round(($item_index / $total_items) * 100),
                'current_step'     => "AI Translating: {$page_title}",
                'details'          => "Translating to {$target_lang}",
                'current_page_id'  => $page_id,
                'current_language' => $target_lang,
                'completed_items'  => $completed_so_far,
            ]);

            try {
                $new_page_id = $engine->translate_page($page_id, $target_lang, $source_lang);

                // Retrieve the language code that was actually used during translation.
                // If auto-detection ran, this will be the resolved code (e.g. 'fr') not 'auto'.
                // Sending it back lets JS pass it on retries so cache keys stay consistent.
                $resolved_lang = get_transient('aitrfoel_detected_lang_' . $page_id . '_' . $target_lang) ?: $source_lang;

                if (is_wp_error($new_page_id)) {
                    $error_code = $new_page_id->get_error_code();
                    $error_count++;

                    // Word-limit class errors: pause and tell JS to stop
                    if (in_array($error_code, ['insufficient_words', 'cooldown_active'], true)) {
                        $queue->update_job($job_id, [
                            'status'          => 'paused',
                            'completed_items' => $completed_so_far,
                            'current_step'    => 'Stopped: Word limit reached',
                            'error_type'      => $error_code,
                        ]);
                        wp_send_json_success([
                            'status'     => 'limit_reached',
                            'error_code' => $error_code,
                            'completed'  => $completed_so_far,
                            'total'      => $total_items,
                        ]);
                        return;
                    }

                    $queue->update_job($job_id, [
                        'progress'        => round((($item_index + 1) / $total_items) * 100),
                        'current_step'    => "Error: {$page_title}",
                        'details'         => wp_strip_all_tags($new_page_id->get_error_message()),
                        'errors'          => $error_count,
                        'completed_items' => $completed_so_far,
                    ]);

                    if ($is_last) {
                        $queue->update_job($job_id, ['status' => 'completed', 'completed_at' => current_time('mysql')]);
                        do_action('aitrfoel_after_bulk_translation', $job_id, $completed_so_far, $total_items);
                    }

                    wp_send_json_success([
                        'status'      => 'item_error',
                        'error'       => $new_page_id->get_error_message(),
                        'completed'   => $completed_so_far,
                        'error_count' => $error_count,
                        'item_index'  => $item_index,
                        'total'       => $total_items,
                    ]);
                    return;
                }

                // Success
                $completed_so_far++;
                $is_partial       = (bool) get_post_meta($new_page_id, '_aitrfoel_partial_translation', true);
                $is_word_limit    = (bool) get_post_meta($new_page_id, '_aitrfoel_word_limit_hit', true);

                // If the partial was caused by word limit exhaustion, stop the queue now
                // instead of retrying (retries would just hit the same wall and waste words).
                if ($is_partial && $is_word_limit) {
                    $queue->update_job($job_id, [
                        'status'          => 'paused',
                        'completed_items' => $completed_so_far - 1, // this page did not fully complete
                        'current_step'    => 'Paused: Word limit reached',
                        'error_type'      => 'limit_reached_during_process',
                    ]);
                    wp_send_json_success([
                        'status'    => 'limit_reached',
                        'completed' => $completed_so_far - 1,
                        'total'     => $total_items,
                    ]);
                    return;
                }

                $queue->update_job($job_id, [
                    'progress'           => round((($item_index + 1) / $total_items) * 100),
                    'current_step'       => "Completed: {$page_title}",
                    'details'            => "Successfully translated to {$target_lang}",
                    'completed_items'    => $completed_so_far,
                    'completed_page_id'  => $page_id,
                    'completed_language' => $target_lang,
                    'current_language'   => $target_lang,
                ]);

                if ($is_last) {
                    $queue->update_job($job_id, ['status' => 'completed', 'completed_at' => current_time('mysql')]);
                    do_action('aitrfoel_after_bulk_translation', $job_id, $completed_so_far, $total_items);
                }

                wp_send_json_success([
                    'status'          => 'ok',
                    'new_page_id'     => $new_page_id,
                    'source_page_id'  => $page_id,
                    'target_language' => $target_lang,
                    'page_title'      => $page_title,
                    'completed'       => $completed_so_far,
                    'error_count'     => $error_count,
                    'item_index'      => $item_index,
                    'total'           => $total_items,
                    'is_last'         => $is_last,
                    'partial'         => $is_partial,
                    'detected_lang'   => $resolved_lang,
                ]);

            } catch (Throwable $e) {
                $error_count++;
                $queue->update_job($job_id, [
                    'progress'        => round((($item_index + 1) / $total_items) * 100),
                    'current_step'    => "Error: {$page_title}",
                    'details'         => mb_substr($e->getMessage(), 0, 500),
                    'errors'          => $error_count,
                    'completed_items' => $completed_so_far,
                ]);

                if ($is_last) {
                    $queue->update_job($job_id, ['status' => 'completed', 'completed_at' => current_time('mysql')]);
                    do_action('aitrfoel_after_bulk_translation', $job_id, $completed_so_far, $total_items);
                }

                // Return success=true so the JS loop continues to the next item
                wp_send_json_success([
                    'status'      => 'item_error',
                    'error'       => $e->getMessage(),
                    'completed'   => $completed_so_far,
                    'error_count' => $error_count,
                    'item_index'  => $item_index,
                    'total'       => $total_items,
                ]);
            }

        } catch (Throwable $e) {
            AITRFOEL_Logger::log('process_bulk_item fatal: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Async Phase 1: Parse page, check word limit, submit uncached strings to proxy.
     * Returns a proxy_job_id immediately (< 2 s) so the PHP request never blocks on OpenAI.
     * Falls back to synchronous translation for Elementor pages or if the proxy async
     * endpoint is not yet deployed.
     */
    public function handle_submit_bulk_item() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            $page_id     = intval(             $_POST['page_id']        ?? 0);
            $target_lang = sanitize_text_field($_POST['target_lang']    ?? '');
            $source_lang = sanitize_text_field($_POST['source_language'] ?? 'auto');
            $item_index  = intval(             $_POST['item_index']     ?? 0);
            $total_items = max(1, intval(      $_POST['total_items']    ?? 1));

            if (!$page_id || !$target_lang) {
                throw new Exception(__('Missing required parameters.', 'ai-translator-for-elementor-and-polylang'));
            }

            $engine       = AITRFOEL_Translation_Engine::get_instance();
            $proxy_job_id = $engine->submit_page_async($page_id, $target_lang, $source_lang);

            // 'sync_required' = Elementor; WP_Error = async endpoint not yet live on proxy
            // Both cases: fall through to synchronous path
            // 'sync_required' = Elementor; WP_Error = async proxy endpoint not yet live.
            // Translating Elementor pages synchronously can take several minutes for large pages.
            // The submit AJAX call has a 60 s JS-side timeout, which is too short.
            // Signal the JS to delegate to aitrfoel_process_bulk_item, which uses a 360 s timeout.
            if ($proxy_job_id === 'sync_required' || is_wp_error($proxy_job_id)) {
                if (is_wp_error($proxy_job_id)) {
                    AITRFOEL_Logger::log('Async submit fell back to sync: ' . $proxy_job_id->get_error_message(), 'info');
                }
                wp_send_json_success([
                    'status'      => 'use_sync_handler',
                    'page_id'     => $page_id,
                    'target_lang' => $target_lang,
                    'item_index'  => $item_index,
                    'total'       => $total_items,
                ]);
                return;
            }

            // 'cache_hit:{key}' — all strings were cached; page already created synchronously
            if (strpos($proxy_job_id, 'cache_hit:') === 0) {
                $transient_key = substr($proxy_job_id, strlen('cache_hit:'));
                $new_page_id   = get_transient($transient_key);
                delete_transient($transient_key);
                wp_send_json_success([
                    'status'          => 'sync_done',
                    'new_page_id'     => $new_page_id ?: 0,
                    'source_page_id'  => $page_id,
                    'target_language' => $target_lang,
                    'item_index'      => $item_index,
                    'total'           => $total_items,
                ]);
                return;
            }

            // Normal async path — job enqueued on proxy
            wp_send_json_success([
                'status'       => 'submitted',
                'proxy_job_id' => $proxy_job_id,
                'page_id'      => $page_id,
                'target_lang'  => $target_lang,
                'item_index'   => $item_index,
                'total'        => $total_items,
            ]);

        } catch (Throwable $e) {
            AITRFOEL_Logger::log('submit_bulk_item error: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Async Phase 2: Check proxy job status; apply translations and create page when done.
     */
    public function handle_poll_bulk_item() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            $proxy_job_id = sanitize_text_field($_POST['proxy_job_id'] ?? '');
            $page_id      = intval(             $_POST['page_id']       ?? 0);
            $target_lang  = sanitize_text_field($_POST['target_lang']   ?? '');
            $item_index   = intval(             $_POST['item_index']    ?? 0);
            $total_items  = max(1, intval(      $_POST['total_items']   ?? 1));

            if (!$proxy_job_id) {
                throw new Exception(__('Missing proxy_job_id.', 'ai-translator-for-elementor-and-polylang'));
            }

            $engine = AITRFOEL_Translation_Engine::get_instance();
            $result = $engine->poll_page_async($proxy_job_id);

            if ($result === 'pending') {
                wp_send_json_success(['status' => 'pending', 'proxy_job_id' => $proxy_job_id]);
                return;
            }

            if (is_wp_error($result)) {
                $code = $result->get_error_code();
                if (in_array($code, ['insufficient_words', 'cooldown_active'], true)) {
                    wp_send_json_success(['status' => 'limit_reached', 'error' => $result->get_error_message()]);
                    return;
                }
                wp_send_json_success(['status' => 'item_error', 'error' => $result->get_error_message(), 'item_index' => $item_index, 'total' => $total_items]);
                return;
            }

            wp_send_json_success([
                'status'          => 'done',
                'new_page_id'     => $result,
                'source_page_id'  => $page_id,
                'target_language' => $target_lang,
                'item_index'      => $item_index,
                'total'           => $total_items,
            ]);

        } catch (Throwable $e) {
            AITRFOEL_Logger::log('poll_bulk_item error: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get translation status and progress
     */
    public function handle_get_translation_status() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get actual status from database
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            $job_status = $queue->get_job_status($job_id);
            
            if (is_wp_error($job_status)) {
                throw new Exception($job_status->get_error_message());
            }
            
            if (!$job_status) {
                throw new Exception(__('Job not found.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Calculate progress percentage
            $progress = 0;
            if ($job_status['total_items'] > 0) {
                $progress = round(($job_status['completed_items'] / $job_status['total_items']) * 100);
            }
            
            $response = [
                'status' => $job_status['status'],
                'progress' => $progress,
                'completed_items' => $job_status['completed_items'],
                'total_items' => $job_status['total_items'],
                'errors' => $job_status['errors'] ?? 0,
                'current_step' => $job_status['current_step'] ?? '',
                'details' => $job_status['details'] ?? '',
                'stage' => $job_status['stage'] ?? 1,
                'stage_description' => $job_status['stage_description'] ?? 'Processing...',
                'current_page_id' => $job_status['current_page_id'] ?? null,
                'current_language' => $job_status['current_language'] ?? null,
                'completed_page_id' => $job_status['completed_page_id'] ?? null,
                'completed_language' => $job_status['completed_language'] ?? null,
                'message' => $this->get_status_message($job_status['status'], $progress),
                // ENHANCED: Add error type and details if job failed/paused
                'error_type' => $job_status['error_type'] ?? null,
                'error_details' => $job_status['error_details'] ?? null
            ];
            
            // Add results if completed
            if ($job_status['status'] === 'completed') {
                $response['results'] = $job_status['results'] ?? [];
            }
            
            // Add error information if job is paused or failed
            if (in_array($job_status['status'], ['paused', 'failed']) && !empty($job_status['error_type'])) {
                $response['error_info'] = [
                    'type' => $job_status['error_type'],
                    'message' => $job_status['details'] ?? '',
                    'completed' => $job_status['completed_items'],
                    'total' => $job_status['total_items'],
                    'remaining' => $job_status['total_items'] - $job_status['completed_items']
                ];
            }
            
            wp_send_json_success($response);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Pause translation process
     */
    public function handle_pause_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // TODO: Phase 3 - Implement actual pause logic
            
            wp_send_json_success([
                'status' => 'paused',
                'message' => __('Translation paused successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Resume translation process
     */
    public function handle_resume_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Resume translation from queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            // Note: update_status method is not available in the queue manager; using update_job instead
            $queue->update_job($job_id, [ 'status' => 'processing' ]);
            
            // Reschedule processing
            wp_schedule_single_event(time() + 5, 'aitrfoel_process_bulk_translation', [$job_id]);
            
            wp_send_json_success([
                'status' => 'processing',
                'message' => __('Translation resumed successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Cancel translation process
     */
    public function handle_cancel_translation() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Cancel translation in queue
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            // Note: update_status method is not available; use update_job
            $queue->update_job($job_id, [ 'status' => 'cancelled' ]);
            
            wp_send_json_success([
                'status' => 'cancelled',
                'message' => __('Translation cancelled successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Publish all translated pages
     */
    public function handle_publish_translations() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('publish_pages')) {
                throw new Exception(__('You do not have permission to publish pages.', 'ai-translator-for-elementor-and-polylang'));
            }

            $job_id = sanitize_text_field($_POST['job_id'] ?? '');
            
            if (empty($job_id)) {
                throw new Exception(__('Job ID is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            // TODO: Phase 4 - Implement actual publishing logic
            
            wp_send_json_success([
                'published_count' => 4,
                'message' => __('4 translations published successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Get word count estimation for selected pages
     */
    public function handle_get_word_count() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            $pages = sanitize_text_field($_POST['pages'] ?? '');
            
            if (empty($pages)) {
                throw new Exception(__('Pages are required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $page_ids = is_array($_POST['pages']) ? array_map('intval', $_POST['pages']) : explode(',', $pages);
            $total_words = 0;

            foreach ($page_ids as $page_id) {
                $post = get_post($page_id);
                if ($post) {
                    // Get basic post content
                    $content = wp_strip_all_tags($post->post_content);
                    $title = wp_strip_all_tags($post->post_title);
                    $basic_words = AITRFOEL_Translation_Engine::count_words($content . ' ' . $title);
                    
                    // Try to get Elementor content
                    $elementor_words = 0;
                    try {
                        if (class_exists('AITRFOEL_Widget_Parser')) {
                            $parser = AITRFOEL_Widget_Parser::get_instance();
                            $elementor_data = get_post_meta($page_id, '_elementor_data', true);
                            
                            if (!empty($elementor_data)) {
                                $translatable_strings = $parser->extract_translatable_strings($elementor_data);
                                
                                foreach ($translatable_strings as $string_data) {
                                    if (!empty($string_data['original'])) {
                                        $elementor_words += AITRFOEL_Translation_Engine::count_words(wp_strip_all_tags($string_data['original']));
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) {
                        AITRFOEL_Logger::log('Error parsing Elementor content for word count: ' . $e->getMessage(), 'warning', $page_id);
                    }
                    
                    // Use the higher count (basic or elementor + basic)
                    $total_words += max($basic_words, $elementor_words + AITRFOEL_Translation_Engine::count_words($title));
                }
            }

            // Basic cost estimation (simplified)
            $estimated_cost = round($total_words * 0.0001, 4);

            wp_send_json_success([
                'total_words' => $total_words,
                'estimated_tokens' => intval($total_words * 1.3), // Rough token estimation
                'estimated_cost_openai' => $estimated_cost
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Prescan word counts for a batch of post IDs and cache in postmeta.
     */
    public function handle_prescan_word_counts() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
            }

            $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : [];
            
            // If no specific post_ids provided, query up to 20 published posts missing _aitrfoel_word_count
            if (empty($post_ids)) {
                global $wpdb;
                $post_types = ['page', 'post'];
                if (post_type_exists('elementor_library')) {
                    $post_types[] = 'elementor_library';
                }
                $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
                $query = $wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_aitrfoel_word_count')
                     WHERE p.post_type IN ($placeholders)
                       AND p.post_status = 'publish'
                       AND pm.meta_value IS NULL
                     ORDER BY p.ID DESC
                     LIMIT 20",
                    $post_types
                );
                $found = $wpdb->get_col($query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query built with $wpdb->prepare(); table names are internal ($wpdb->prefix + fixed name)
                $post_ids = array_map('intval', $found);
            }

            $detector = class_exists('AITRFOEL_Content_Detector') ? AITRFOEL_Content_Detector::get_instance() : null;
            $word_counts = [];

            foreach ($post_ids as $post_id) {
                if ($detector) {
                    $word_counts[$post_id] = $detector->get_cached_word_count($post_id);
                } else {
                    $word_counts[$post_id] = 0;
                }
            }

            wp_send_json_success([
                'word_counts'   => $word_counts,
                'scanned_count' => count($word_counts),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
    
    /**
     * Get status message based on job status and progress
     */
    private function get_status_message($status, $progress) {
        switch ($status) {
            case 'pending':
                return __('Translation queued and waiting to start...', 'ai-translator-for-elementor-and-polylang');
            case 'processing':
                /* translators: %d: Progress percentage */
                return sprintf(__('Translation in progress... %d%% completed', 'ai-translator-for-elementor-and-polylang'), $progress);
            case 'completed':
                return __('Translation completed successfully!', 'ai-translator-for-elementor-and-polylang');
            case 'failed':
                return __('Translation failed. Please check the error details.', 'ai-translator-for-elementor-and-polylang');
            case 'cancelled':
                return __('Translation was cancelled.', 'ai-translator-for-elementor-and-polylang');
            default:
                return __('Unknown status.', 'ai-translator-for-elementor-and-polylang');
        }
    }
    
    /**
     * Debug test endpoint
     */
    public function handle_debug_test() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $debug_info = [
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plugin_version' => '1.0.1',
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'timestamp' => current_time('mysql'),
                'classes' => [
                    'AITRFOEL_Bulk_Translation_Queue' => class_exists('AITRFOEL_Bulk_Translation_Queue'),
                    'AITRFOEL_Translation_Engine' => class_exists('AITRFOEL_Translation_Engine'),
                    'aitrfoel_Background_Process' => false, // Removed background processing
                    'AITRFOEL_OpenAI_API' => class_exists('AITRFOEL_OpenAI_API')
                ],
                'database' => $this->check_database_status(),
                'api_config' => $this->check_api_config(),
                'cron' => $this->check_cron_status()
            ];
            
            wp_send_json_success($debug_info);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Check database status
     */
    private function check_database_status() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        $job_count = 0;
        if ($table_exists) {
            $job_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        }
        
        return [
            'table_exists' => $table_exists,
            'table_name' => $table_name,
            'job_count' => $job_count
        ];
    }
    
    /**
     * Check API configuration
     */
    private function check_api_config() {
        $openai_key = get_option('aitrfoel_openai_api_key');
        
        return [
            'openai_configured' => !empty($openai_key),
            'openai_key_length' => strlen($openai_key ?? ''),
            'openai_key_prefix' => substr($openai_key ?? '', 0, 10) . '...'
        ];
    }
    
    /**
     * Check cron status
     */
    private function check_cron_status() {
        $cron_events = wp_get_scheduled_event('aitrfoel_process_bulk_translation');
        $all_crons = get_option('cron');
        
        // Count aitrfoel_process_bulk_translation events
        $aitrfoel_cron_count = 0;
        if (is_array($all_crons)) {
            foreach ($all_crons as $time => $hooks) {
                if (isset($hooks['aitrfoel_process_bulk_translation'])) {
                    $aitrfoel_cron_count += count($hooks['aitrfoel_process_bulk_translation']);
                }
            }
        }
        
        return [
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_scheduled' => $cron_events ? gmdate('Y-m-d H:i:s', $cron_events->timestamp) : null,
            'aitrfoel_cron_count' => $aitrfoel_cron_count,
            'total_cron_events' => is_array($all_crons) ? count($all_crons) : 0
        ];
    }
    
    /**
     * Monitor jobs endpoint
     */
    public function handle_monitor_jobs() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $queue = AITRFOEL_Bulk_Translation_Queue::get_instance();
            
            // Get recent jobs (last 20)
            global $wpdb;
            $table_name = $wpdb->prefix . 'aitrfoel_bulk_jobs';
            
            $jobs = $wpdb->get_results("
                SELECT * FROM {$table_name} 
                ORDER BY created_at DESC 
                LIMIT 20
            ");
            
            $stats = $queue->get_statistics();
            
            $data = [
                'jobs' => $jobs,
                'stats' => $stats,
                'cron_info' => $this->get_cron_info(),
                'timestamp' => current_time('mysql')
            ];
            
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get system logs endpoint
     */
    public function handle_get_system_logs() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            $logs = $this->get_recent_logs();
            $cron_info = $this->get_cron_info();
            
            $data = [
                'logs' => $logs,
                'cron_info' => $cron_info,
                'php_error_log' => $this->check_php_error_log(),
                'timestamp' => current_time('mysql')
            ];
            
            wp_send_json_success($data);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Get recent logs from error_log
     */
    private function get_recent_logs() {
        $logs = [];
        
        // Try to read error log
        $error_log_paths = [
            ini_get('error_log'),
            WP_CONTENT_DIR . '/debug.log',
            '/var/log/php_errors.log'
        ];
        
        foreach ($error_log_paths as $log_path) {
            if ($log_path && file_exists($log_path) && is_readable($log_path)) {
                $lines = file($log_path);
                if ($lines) {
                    $recent_lines = array_slice($lines, -50); // Last 50 lines
                    foreach ($recent_lines as $line) {
                        if (strpos($line, 'EAT') !== false || strpos($line, 'aitrfoel_') !== false) {
                            $logs[] = [
                                'time' => current_time('mysql'),
                                'message' => trim($line)
                            ];
                        }
                    }
                    break; // Use first available log
                }
            }
        }
        
        return array_slice($logs, -20); // Last 20 EAT-related entries
    }
    
    /**
     * Check PHP error log for critical errors
     */
    private function check_php_error_log() {
        $error_info = [
            'has_errors' => false,
            'recent_errors' => []
        ];
        
        $log_path = ini_get('error_log');
        if ($log_path && file_exists($log_path) && is_readable($log_path)) {
            $lines = file($log_path);
            if ($lines) {
                $recent_lines = array_slice($lines, -20);
                foreach ($recent_lines as $line) {
                    if (strpos($line, 'Fatal error') !== false || strpos($line, 'Parse error') !== false) {
                        $error_info['has_errors'] = true;
                        $error_info['recent_errors'][] = trim($line);
                    }
                }
            }
        }
        
        return $error_info;
    }
    
    /**
     * Get cron information
     */
    private function get_cron_info() {
        $cron_array = _get_cron_array();
        $scheduled_events = [];
        
        foreach ($cron_array as $time => $hooks) {
            if (isset($hooks['aitrfoel_process_bulk_translation'])) {
                foreach ($hooks['aitrfoel_process_bulk_translation'] as $sig => $event) {
                    $scheduled_events[] = [
                        'time' => gmdate('Y-m-d H:i:s', $time),
                        'args' => $event['args']
                    ];
                }
            }
        }
        
        return [
            'wp_cron_enabled' => !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON),
            'scheduled_events' => $scheduled_events,
            'next_cron_run' => wp_next_scheduled('wp_version_check') ? gmdate('Y-m-d H:i:s', wp_next_scheduled('wp_version_check')) : 'Not scheduled'
        ];
    }
    
    /**
     * Force cron execution
     */
    public function handle_force_cron() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Trigger cron
            spawn_cron();
            
            wp_send_json_success([
                'message' => __('Cron triggered successfully.', 'ai-translator-for-elementor-and-polylang')
            ]);
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }
    
    /**
     * Process pending jobs endpoint
     */
    public function handle_process_pending() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('edit_pages')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            // Get plugin instance and process pending jobs
            $plugin = AITRFOEL_Main::get_instance();
            $result = $plugin->process_pending_jobs();
            
            if ($result) {
                wp_send_json_success([
                    'message' => 'Pending job processed successfully',
                    'timestamp' => current_time('mysql')
                ]);
            } else {
                wp_send_json_error('No pending jobs found or processing failed');
            }
            
        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Handle refresh table data request
     */
    public function handle_refresh_table_data() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            // Check capabilities
            if (!current_user_can('edit_pages') && !current_user_can('edit_posts')) {
                throw new Exception('Insufficient permissions');
            }

            // Get admin menu instance
            if (!class_exists('AITRFOEL_Admin_Menu')) {
                throw new Exception('Admin menu class not found');
            }

            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            
            // Get fresh page data
            $pages_data = $admin_menu->get_pages_with_translations();
            
            // Separate into pages, posts, products, and custom post types — matching
            // the dashboard tab structure exactly (pages / posts / products / custom).
            $pages_only    = [];
            $posts_only    = [];
            $products_only = [];
            $custom_only   = [];
            foreach ($pages_data as $group) {
                $pt = $group['source']->post_type;
                if ($pt === 'page') {
                    $pages_only[] = $group;
                } elseif ($pt === 'product') {
                    $products_only[] = $group;
                } elseif ($pt === 'post') {
                    $posts_only[] = $group;
                } else {
                    $custom_only[] = $group;
                }
            }

            // Render pages HTML
            ob_start();
            $admin_menu->render_pages_rows($pages_only);
            $pages_html = ob_get_clean();

            // Render posts HTML
            ob_start();
            $admin_menu->render_pages_rows($posts_only);
            $posts_html = ob_get_clean();

            // Render products HTML
            ob_start();
            $admin_menu->render_pages_rows($products_only);
            $products_html = ob_get_clean();

            // Render custom post types HTML
            ob_start();
            $admin_menu->render_pages_rows($custom_only);
            $custom_html = ob_get_clean();

            wp_send_json_success([
                'html' => $pages_html, // Backward compat: pages HTML in 'html'
                'pages_html' => $pages_html,
                'posts_html' => $posts_html,
                'products_html' => $products_html,
                'custom_html' => $custom_html,
                'pages_count' => count($pages_only),
                'posts_count' => count($posts_only),
                'products_count' => count($products_only),
                'custom_count' => count($custom_only),
                'message' => 'Table data refreshed successfully'
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle page deletion request
     */
    public function handle_delete_page() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            // Check permissions
            if (!current_user_can('delete_pages') && !current_user_can('delete_posts')) {
                throw new Exception('Insufficient permissions to delete content');
            }

            $page_id = intval($_POST['page_id']);
            
            if (!$page_id) {
                throw new Exception('Invalid page ID');
            }

            // Get page info for logging
            $page = get_post($page_id);
            if (!$page) {
                throw new Exception('Page not found');
            }

            if (!current_user_can('delete_post', $page_id)) {
                throw new Exception('Insufficient permissions to delete this content');
            }

            $deletable_types = array_merge(
                ['page', 'post', 'product'],
                get_post_types(['public' => true, '_builtin' => false], 'names')
            );
            if (!in_array($page->post_type, $deletable_types, true)) {
                throw new Exception('This content type cannot be deleted through this interface');
            }

            // Check if this is a translated page (not an original)
            $is_translation = false;
            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            
            if ($multilingual && method_exists($multilingual, 'get_original_post_id')) {
                $original_id = $multilingual->get_original_post_id($page_id);
                // If original_id is different from page_id, this is a translation
                $is_translation = ($original_id && $original_id !== $page_id);
            }
            
            $result = wp_delete_post($page_id, true);
            $action_msg = 'deleted permanently';

            if (!$result) {
                throw new Exception('Failed to delete page');
            }

            // Log the action
            AITRFOEL_Logger::log("Page '{$page->post_title}' {$action_msg} by user " . get_current_user_id() . ($is_translation ? ' (translation)' : ' (original)'), 'info', $page_id);

            wp_send_json_success([
                'message' => sprintf('Page "%s" %s successfully', $page->post_title, $action_msg),
                'page_id' => $page_id,
                'is_translation' => $is_translation,
                'trashed' => false,
                'permanently_deleted' => true
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Bulk-delete multiple translated posts permanently.
     * Only posts that are confirmed translations (not originals) are deleted.
     */
    public function handle_bulk_delete_translations() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('delete_pages') && !current_user_can('delete_posts')) {
                throw new Exception('Insufficient permissions to delete content');
            }

            $raw_ids = isset($_POST['post_ids']) ? (array) $_POST['post_ids'] : [];
            $post_ids = array_map('absint', $raw_ids);
            $post_ids = array_filter($post_ids);

            if (empty($post_ids)) {
                throw new Exception('No post IDs provided');
            }

            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            $deleted  = [];
            $skipped  = [];
            $failed   = [];

            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                $bulk_deletable_types = array_merge(
                    ['page', 'post', 'product'],
                    get_post_types(['public' => true, '_builtin' => false], 'names')
                );
                if (!$post || !in_array($post->post_type, $bulk_deletable_types, true)) {
                    $skipped[] = $post_id;
                    continue;
                }

                // Only trash confirmed translations
                $is_translation = false;
                if ($multilingual && method_exists($multilingual, 'get_original_post_id')) {
                    $original_id = $multilingual->get_original_post_id($post_id);
                    $is_translation = ($original_id && $original_id !== $post_id);
                }

                if (!$is_translation) {
                    $skipped[] = $post_id;
                    continue;
                }

                // The generic delete_posts check above only says the user may delete
                // *some* posts; each post needs its own check (author, status, type).
                if (!current_user_can('delete_post', $post_id)) {
                    $failed[] = $post_id;
                    continue;
                }

                $result = wp_delete_post($post_id, true);
                if ($result) {
                    $deleted[] = $post_id;
                    AITRFOEL_Logger::log("Translation '{$post->post_title}' permanently deleted (bulk) by user " . get_current_user_id(), 'info', $post_id);
                } else {
                    $failed[] = $post_id;
                }
            }

            wp_send_json_success([
                'deleted' => $deleted,
                'skipped' => $skipped,
                'failed'  => $failed,
                'message' => sprintf('%d translation(s) deleted.', count($deleted)),
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Publish a draft/pending translation (or any post) from the dashboard / review
     * screen.
     */
    public function handle_publish_page() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }
            $post_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
            if (!$post_id) {
                throw new Exception('Invalid page ID');
            }
            $post = get_post($post_id);
            if (!$post) {
                throw new Exception('Page not found');
            }
            // Publishing needs both: the right to edit this specific post, and the
            // publish capability of its post type (publish_pages for pages, etc.).
            $pto = get_post_type_object($post->post_type);
            if (!current_user_can('edit_post', $post_id) || !$pto || !current_user_can($pto->cap->publish_posts)) {
                throw new Exception('Insufficient permissions to publish');
            }
            if ($post->post_status === 'publish') {
                wp_send_json_success(['message' => 'Already published', 'page_id' => $post_id, 'already' => true]);
            }

            $result = wp_update_post(['ID' => $post_id, 'post_status' => 'publish'], true);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Translation '{$post->post_title}' published by user " . get_current_user_id(), 'info', $post_id);
            }

            wp_send_json_success([
                'message'  => sprintf('"%s" published.', $post->post_title),
                'page_id'  => $post_id,
                'view_url' => get_permalink($post_id),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Re-translate a post into a language (the review screen's "Update" action),
     * refreshing the existing translation from the current source content.
     */
    public function handle_retranslate_post() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }
            $source_id = isset($_POST['source_id']) ? absint($_POST['source_id']) : 0;
            $lang      = isset($_POST['lang']) ? sanitize_text_field(wp_unslash($_POST['lang'])) : '';
            if (!$source_id || $lang === '') {
                throw new Exception('Missing parameters');
            }
            if (!current_user_can('edit_post', $source_id)) {
                throw new Exception('Insufficient permissions');
            }
            if (!class_exists('AITRFOEL_Translation_Engine')) {
                throw new Exception('Translation engine not available');
            }

            $result = AITRFOEL_Translation_Engine::get_instance()->translate_page($source_id, $lang, 'auto');
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            wp_send_json_success([
                'message' => 'Translation updated.',
                'post_id' => (int) $result,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Move every translation of a source page to trash (recoverable), keeping
     * the source itself untouched. Triggered by the per-row "Delete translations"
     * button on the dashboard.
     */
    public function handle_delete_page_translations() {
        try {
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('delete_pages') && !current_user_can('delete_posts')) {
                throw new Exception('Insufficient permissions to delete content');
            }

            $source_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
            if (!$source_id) {
                throw new Exception('Invalid page ID');
            }

            $source = get_post($source_id);
            if (!$source) {
                throw new Exception('Page not found');
            }

            if (!current_user_can('edit_post', $source_id)) {
                throw new Exception('Insufficient permissions to manage this content');
            }

            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            if (!$multilingual || !method_exists($multilingual, 'get_post_translations')) {
                throw new Exception('Built-in language system not available');
            }

            // [ lang_code => post_id ] for every member of the group, source included.
            $translations = $multilingual->get_post_translations($source_id);

            $deleted = [];
            $failed  = [];
            foreach ((array) $translations as $tid) {
                $tid = (int) $tid;
                if (!$tid || $tid === $source_id) {
                    continue; // never delete the source page itself
                }
                $tpost = get_post($tid);
                if (!$tpost) {
                    continue;
                }
                if (!current_user_can('delete_post', $tid)) {
                    $failed[] = $tid;
                    continue;
                }
                if (wp_delete_post($tid, true)) {
                    $deleted[] = $tid;
                    AITRFOEL_Logger::log("Translation '{$tpost->post_title}' permanently deleted (delete-all for source #{$source_id}) by user " . get_current_user_id(), 'info', $tid);
                } else {
                    $failed[] = $tid;
                }
            }

            wp_send_json_success([
                'deleted'   => $deleted,
                'failed'    => $failed,
                'source_id' => $source_id,
                'message'   => sprintf('%d translation(s) permanently deleted.', count($deleted)),
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Rewrite [elementor-template id="X"] shortcodes in translated posts so each
     * id references the per-language template. Split out from Repair Links so it
     * can be run independently. scan=1 => dry run (preview only).
     */
    public function handle_repair_template_shortcodes() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        $apply  = empty($_POST['scan']); // scan=1 => dry run
        $report = AITRFOEL_Builtin_Integration::get_instance()->repair_template_shortcodes($apply);

        wp_send_json_success($report);
    }

    /**
     * Get translation settings
     */
    public function handle_get_translation_settings() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            $settings = get_option('aitrfoel_translation_settings', [
                'exclude_words' => [],
                'smart_suggestions_enabled' => true,
                'updated_at' => time()
            ]);

            $seo_plugin_name = AITRFOEL_Translation_Engine::detected_seo_plugin();
            $has_yoast = ($seo_plugin_name === 'Yoast SEO');
            $has_seo_plugin = ($seo_plugin_name !== '');

            $settings['translated_page_status'] = get_option('aitrfoel_translated_page_status', 'publish');
            $settings['seo_translation_enabled'] = (bool) get_option('aitrfoel_seo_translation_enabled', true);
            $settings['seo_plugin_detected'] = $has_seo_plugin;
            $settings['seo_plugin_name'] = $seo_plugin_name;

            wp_send_json_success($settings);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Save translation settings
     */
    public function handle_save_translation_settings() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            $exclude_words = isset($_POST['exclude_words']) ? 
                array_map('sanitize_text_field', $_POST['exclude_words']) : [];
            
            $smart_suggestions = isset($_POST['smart_suggestions_enabled']) ? 
                (bool) $_POST['smart_suggestions_enabled'] : true;

            $translated_page_status = isset($_POST['translated_page_status'])
                ? sanitize_text_field(wp_unslash($_POST['translated_page_status']))
                : 'draft';
            $allowed_statuses = ['draft', 'publish', 'inherit'];
            if (!in_array($translated_page_status, $allowed_statuses, true)) {
                $translated_page_status = 'draft';
            }

            $seo_plugin_name = AITRFOEL_Translation_Engine::detected_seo_plugin();
            $has_yoast = ($seo_plugin_name === 'Yoast SEO');
            $has_seo_plugin = ($seo_plugin_name !== '');

            $seo_translation_enabled = isset($_POST['seo_translation_enabled'])
                ? (bool) wp_unslash($_POST['seo_translation_enabled'])
                : false;
            if (!$has_seo_plugin) {
                $seo_translation_enabled = false;
            }

            $settings = [
                'exclude_words' => array_filter($exclude_words), // Remove empty values
                'smart_suggestions_enabled' => $smart_suggestions,
                'updated_at' => time()
            ];

            update_option('aitrfoel_translation_settings', $settings);
            update_option('aitrfoel_translated_page_status', $translated_page_status);
            update_option('aitrfoel_seo_translation_enabled', $seo_translation_enabled);

            // Log the change
            AITRFOEL_Logger::log(
                0, 
                'info', 
                'Translation settings updated: ' . wp_json_encode([
                    'exclude_words_count' => count($settings['exclude_words']),
                    'translated_page_status' => $translated_page_status,
                    'seo_translation_enabled' => $seo_translation_enabled,
                ])
            );


            wp_send_json_success([
                'message' => 'Settings saved successfully',
                'settings' => array_merge($settings, [
                    'translated_page_status' => $translated_page_status,
                    'seo_translation_enabled' => $seo_translation_enabled,
                    'seo_plugin_detected' => $has_seo_plugin,
                ])
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get smart suggestions for exclude words
     */
    public function handle_get_smart_suggestions() {
        try {
            // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'aitrfoel_ajax_nonce')) {
                throw new Exception('Security check failed');
            }

            $suggestions = [
                'brand_names' => [
                    'label' => 'Brand Names',
                    'items' => ['WordPress', 'Elementor', 'WooCommerce', 'Polylang']
                ],
                'technical_terms' => [
                    'label' => 'Technical Terms', 
                    'items' => ['API', 'CSS', 'HTML', 'JavaScript', 'PHP', 'URL']
                ],
                'contact_info' => [
                    'label' => 'Contact Info',
                    'items' => ['Email', 'Phone', 'Address', 'Contact']
                ],
                'common_excludes' => [
                    'label' => 'Common Excludes',
                    'items' => ['Lorem ipsum', 'Lorem', 'ipsum', 'Admin', 'Dashboard']
                ]
            ];

            wp_send_json_success($suggestions);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Debug exclusions - test how they're applied
     */
    public function handle_debug_exclusions() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                throw new Exception('Insufficient permissions');
            }

            // Get current settings
            $settings = get_option('aitrfoel_translation_settings', []);
            $exclude_words = $settings['exclude_words'] ?? [];

            // Create test OpenAI instance
            $openai = new AITRFOEL_OpenAI_API();
            
            // Provide a static prompt preview to confirm exclusions are recognized
            $test_text = "Welcome to WordPress! Contact our API team for Elementor support.";
            wp_send_json_success([
                'current_settings' => $settings,
                'exclude_words' => $exclude_words,
                'test_text' => $test_text,
                'prompt_preview' => 'Exclusion rules will be applied to these words: ' . implode(', ', $exclude_words),
                'exclusions_active' => !empty($exclude_words)
            ]);

        } catch (Exception $e) {
            wp_send_json_error([
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if word limit allows translation
     */
    private function check_word_limit($estimated_words = 0) {
        // Get license manager instance
        $license_manager = AITRFOEL_License_Manager_Server::get_instance();
        $license_status = $license_manager->get_license_status();
        
        // Check if in cooldown
        if (isset($license_status['in_cooldown']) && $license_status['in_cooldown']) {
            $cooldown_expiry = $license_status['cooldown_expiry'];
            $remaining_days = ceil(($cooldown_expiry - time()) / (24 * 60 * 60));
            
            
            
            throw new Exception(json_encode([
                'type' => 'cooldown',
                'message' => sprintf(
                    /* translators: %d: Number of days remaining */
                    __('You are in a monthly cooldown period. Your translation limit will reset in %d days. Upgrade your plan to continue translating immediately.', 'ai-translator-for-elementor-and-polylang'),
                    $remaining_days
                ),
                'cooldown_expiry' => $cooldown_expiry,
                'remaining_days' => $remaining_days,
                'remaining_ms' => ($cooldown_expiry - time()) * 1000,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
            ]));
        }
        
        // Get current usage and limits
        $words_used = $license_status['words_used'];
        $word_limit = $license_status['word_limit'];
        $words_left = $word_limit === -1 ? -1 : max(0, $word_limit - $words_used);
        
        AITRFOEL_Logger::log(sprintf(
            'Word limit validation: Plan=%s, Used=%d, Limit=%d, Left=%d, Estimated=%d',
            $license_status['plan'],
            $words_used,
            $word_limit,
            $words_left,
            $estimated_words
        ), 'info');
        
        
            // Check if limit reached (excluding unlimited plans)
        if ($word_limit !== -1 && $words_left <= 0) {
            AITRFOEL_Logger::log(sprintf(
                'Translation BLOCKED: Word limit reached! Plan=%s, Used=%d, Limit=%d, Left=%d',
                $license_status['plan'],
                $words_used,
                $word_limit,
                $words_left
            ), 'warning');
            
            throw new Exception(json_encode([
                'type' => 'limit_reached',
                'message' => sprintf(
                    /* translators: 1: Word limit number, 2: Plan name */
                    __('Word limit reached! You have used all %1$s words in your %2$s plan. Upgrade to continue or wait for the monthly reset period.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($word_limit),
                    $license_status['plan']
                ),
                'words_used' => $words_used,
                'word_limit' => $word_limit,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
            ]));
        }        // Check if estimated words exceed remaining (excluding unlimited plans)
        if ($word_limit !== -1 && $estimated_words > 0 && $estimated_words > $words_left) {
            
            
            throw new Exception(json_encode([
                'type' => 'insufficient_words',
                'message' => sprintf(
                    /* translators: 1: Words required, 2: Words remaining, 3: Plan name */
                    __('Not enough words remaining. This translation requires approximately %1$s words, but you only have %2$s words left in your %3$s plan.', 'ai-translator-for-elementor-and-polylang'),
                    number_format($estimated_words),
                    number_format($words_left),
                    $license_status['plan']
                ),
                'estimated_words' => $estimated_words,
                'words_left' => $words_left,
                'words_used' => $words_used,
                'word_limit' => $word_limit,
                'plan' => $license_status['plan'],
                'upgrade_url' => (function_exists('eatp_fs') && is_object(eatp_fs()) && method_exists(eatp_fs(), 'is_registered') && eatp_fs()->is_registered()) ? eatp_fs()->get_upgrade_url() : 'https://meeptech.com/upgrade?utm_source=wp-plugin&utm_medium=free-plugin&utm_campaign=pro-upgrade'
            ]));
        }
        
        
        
        return [
            'words_left' => $words_left,
            'limit' => $word_limit,
            'license' => $license_status['plan'],
            'words_used' => $words_used
        ];
    }
    
    /**
     * Test server usage retrieval
     * This endpoint tests the server API connection and returns usage data
     */
    public function handle_test_server_usage() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }
            
            AITRFOEL_Logger::log('Testing server usage retrieval...', 'info');
            
            // Delete cache transient to force live usage from server
            delete_transient('aitrfoel_license_status_cache');
            
            // Get license manager instance
            $license_manager = AITRFOEL_License_Manager_Server::get_instance();
            
            // Get license status (this will call the server)
            $license_status = $license_manager->get_license_status();
            
            AITRFOEL_Logger::log('License status retrieved: ' . json_encode($license_status), 'info');
            
            $used         = isset($license_status['words_used']) ? (int) $license_status['words_used'] : (int) ($license_status['words_translated'] ?? 0);
            $limit        = isset($license_status['word_limit']) ? (int) $license_status['word_limit'] : (int) ($license_status['total_words'] ?? 0);
            $is_unlimited = ($limit <= 0 || !empty($license_status['unlimited']) || !empty($license_status['is_unlimited']));
            $pct          = ($limit > 0 && !$is_unlimited) ? min(100, round(($used / $limit) * 100)) : 0;
            
            $upg_dismissed    = (bool) get_option('aitrfoel_upgrade_banner_dismissed', false);
            $upgrade_eligible = (!$is_unlimited && $pct >= 80 && !$upg_dismissed);

            $review_dismissed = get_option('aitrfoel_review_dismissed', false);
            $snoozed_until    = (int) get_option('aitrfoel_review_snoozed_until', 0);
            $success_count    = (int) get_option('aitrfoel_successful_translations_count', 0);
            $review_eligible  = (!$review_dismissed && time() > $snoozed_until && $success_count >= 2);

            // Return detailed response
            wp_send_json_success([
                'message'          => 'Server usage retrieved successfully',
                'data'             => $license_status,
                'upgrade_eligible' => $upgrade_eligible,
                'usage_pct'        => $pct,
                'words_used'       => $used,
                'words_limit'      => $limit,
                'review_eligible'  => $review_eligible,
                'timestamp'        => current_time('mysql'),
                'api_url'          => 'https://meeptech.com/api/eat/get-usage'
            ]);
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log('❌ Test server usage failed: ' . $e->getMessage(), 'error');
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate menus: clone default-language menus, translate items, assign per language.
     */
    public function handle_auto_translate_menus() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
                throw new Exception(__('Built-in language system is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $builtin = AITRFOEL_Builtin_Integration::get_instance();
            $default_lang = $builtin->get_default_language();
            $source_lang = $default_lang;

            if ($target_lang === $default_lang) {
                throw new Exception(__('Cannot translate to the default language.', 'ai-translator-for-elementor-and-polylang'));
            }

            $api = AITRFOEL_OpenAI_API::get_instance();
            $menu_locations = get_registered_nav_menus();
            $nav_menu_locations = get_nav_menu_locations();
            $assignments = get_option('aitrfoel_menu_assignments', []);
            $created_menus = [];
            $lang_upper = strtoupper($target_lang);

            foreach ($menu_locations as $loc_slug => $loc_name) {
                // Get the menu assigned to this location
                $menu_id = isset($nav_menu_locations[$loc_slug]) ? $nav_menu_locations[$loc_slug] : 0;
                if (!$menu_id) {
                    continue;
                }

                $source_menu = wp_get_nav_menu_object($menu_id);
                if (!$source_menu) {
                    continue;
                }

                $source_items = wp_get_nav_menu_items($menu_id);
                if (empty($source_items)) {
                    continue;
                }

                // Check if a translated menu already exists for this location/lang
                $existing_id = isset($assignments[$loc_slug][$target_lang]) ? intval($assignments[$loc_slug][$target_lang]) : 0;
                if ($existing_id > 0 && wp_get_nav_menu_object($existing_id)) {
                    // Already assigned — skip
                    $created_menus[] = [
                        'location' => $loc_name,
                        'menu_name' => wp_get_nav_menu_object($existing_id)->name,
                        'skipped' => true,
                    ];
                    continue;
                }

                // Collect labels to translate in batch
                $labels = [];
                foreach ($source_items as $item) {
                    $label = trim($item->title);
                    if ($label !== '' && !in_array($label, $labels, true)) {
                        $labels[] = $label;
                    }
                }

                // Batch translate
                $translations = [];
                if (!empty($labels)) {
                    $result = $api->translate_batch($labels, $target_lang, $source_lang);
                    if (!is_wp_error($result)) {
                        $translations = $result;
                    }
                }

                // Create new menu
                $new_menu_name = $source_menu->name . ' ' . $lang_upper;
                // Avoid duplicates
                $existing = wp_get_nav_menu_object($new_menu_name);
                if ($existing) {
                    $new_menu_id = $existing->term_id;
                    // Clear existing items
                    $old_items = wp_get_nav_menu_items($new_menu_id);
                    if ($old_items) {
                        foreach ($old_items as $old_item) {
                            wp_delete_post($old_item->ID, true);
                        }
                    }
                } else {
                    $new_menu_id = wp_create_nav_menu($new_menu_name);
                    if (is_wp_error($new_menu_id)) {
                        continue;
                    }
                }

                // Clone menu items with translated labels, pointing to translated posts
                $id_map = []; // old DB_ID => new DB_ID for parent mapping
                foreach ($source_items as $item) {
                    $translated_title = isset($translations[$item->title]) ? $translations[$item->title] : $item->title;

                    // Find translated post/page for this menu item
                    $object_id = $item->object_id;
                    $item_url = $item->url;
                    if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                        $post_translations = $builtin->get_post_translations($object_id);
                        if (isset($post_translations[$target_lang])) {
                            $object_id = $post_translations[$target_lang];
                            $item_url = ''; // Let WordPress generate from object_id
                        }
                    }

                    $args = [
                        'menu-item-title'     => $translated_title,
                        'menu-item-url'       => $item_url,
                        'menu-item-object'    => $item->object,
                        'menu-item-object-id' => $object_id,
                        'menu-item-type'      => $item->type,
                        'menu-item-status'    => 'publish',
                        'menu-item-target'    => $item->target,
                        'menu-item-classes'   => implode(' ', (array) $item->classes),
                        'menu-item-xfn'       => $item->xfn,
                        'menu-item-position'  => $item->menu_order,
                    ];

                    // Handle parent mapping
                    if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                        $args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                    }

                    $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);
                    if (!is_wp_error($new_item_id)) {
                        $id_map[$item->db_id] = $new_item_id;
                    }
                }

                // Auto-assign
                if (!isset($assignments[$loc_slug])) {
                    $assignments[$loc_slug] = [];
                }
                $assignments[$loc_slug][$target_lang] = $new_menu_id;

                $created_menus[] = [
                    'location' => $loc_name,
                    'menu_name' => $new_menu_name,
                    'items' => count($source_items),
                    'skipped' => false,
                ];
            }

            update_option('aitrfoel_menu_assignments', $assignments);

            // Build a direct menu ID map (source_id → [lang → translated_id])
            // This is used as a fallback in filter_nav_menu_by_language() for menus
            // not assigned to theme locations (e.g. Elementor Nav Menu widgets).
            $menu_id_map = get_option('aitrfoel_menu_id_map', []);

            // Record location-based menus in the ID map
            foreach ($assignments as $loc_slug => $lang_map) {
                $source_id = isset($nav_menu_locations[$loc_slug]) ? (int) $nav_menu_locations[$loc_slug] : 0;
                if ($source_id > 0 && isset($lang_map[$target_lang])) {
                    if (!isset($menu_id_map[$source_id])) {
                        $menu_id_map[$source_id] = [];
                    }
                    $menu_id_map[$source_id][$target_lang] = (int) $lang_map[$target_lang];
                }
            }

            // Collect IDs of menus that are already translated versions (not sources)
            $translated_ids = [];
            foreach ($menu_id_map as $src => $lang_map) {
                foreach ($lang_map as $l => $tid) {
                    $translated_ids[] = (int) $tid;
                }
            }
            // Also include location-assigned source IDs that were already processed
            $processed_source_ids = [];
            foreach ($nav_menu_locations as $loc => $loc_mid) {
                if ((int) $loc_mid > 0) {
                    $processed_source_ids[] = (int) $loc_mid;
                }
            }

            // Translate remaining menus not assigned to any theme location
            $all_menus = wp_get_nav_menus();
            foreach ($all_menus as $menu_obj) {
                $src_menu_id = (int) $menu_obj->term_id;

                // Skip if already processed (assigned to a location)
                if (in_array($src_menu_id, $processed_source_ids, true)) {
                    continue;
                }
                // Skip if this is a translated menu created by us
                if (in_array($src_menu_id, $translated_ids, true)) {
                    continue;
                }
                // Skip if already has a translation for this language
                if (isset($menu_id_map[$src_menu_id][$target_lang])) {
                    $existing_obj = wp_get_nav_menu_object($menu_id_map[$src_menu_id][$target_lang]);
                    if ($existing_obj) {
                        $created_menus[] = [
                            'location' => '(no location)',
                            'menu_name' => $existing_obj->name,
                            'skipped' => true,
                        ];
                        continue;
                    }
                }

                $src_items = wp_get_nav_menu_items($src_menu_id);
                if (empty($src_items)) {
                    continue;
                }

                // Collect labels to translate
                $labels = [];
                foreach ($src_items as $item) {
                    $label = trim($item->title);
                    if ($label !== '' && !in_array($label, $labels, true)) {
                        $labels[] = $label;
                    }
                }

                // Batch translate
                $label_translations = [];
                if (!empty($labels)) {
                    $result = $api->translate_batch($labels, $target_lang, $source_lang);
                    if (!is_wp_error($result)) {
                        $label_translations = $result;
                    }
                }

                // Create translated menu clone
                $new_name = $menu_obj->name . ' ' . $lang_upper;
                $existing = wp_get_nav_menu_object($new_name);
                if ($existing) {
                    $new_mid = $existing->term_id;
                    $old_items = wp_get_nav_menu_items($new_mid);
                    if ($old_items) {
                        foreach ($old_items as $old_item) {
                            wp_delete_post($old_item->ID, true);
                        }
                    }
                } else {
                    $new_mid = wp_create_nav_menu($new_name);
                    if (is_wp_error($new_mid)) {
                        continue;
                    }
                }

                $id_map = [];
                foreach ($src_items as $item) {
                    $translated_title = isset($label_translations[$item->title]) ? $label_translations[$item->title] : $item->title;
                    $object_id = $item->object_id;
                    $item_url = $item->url;
                    if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                        $post_translations = $builtin->get_post_translations($object_id);
                        if (isset($post_translations[$target_lang])) {
                            $object_id = $post_translations[$target_lang];
                            $item_url = '';
                        }
                    }
                    $item_args = [
                        'menu-item-title'     => $translated_title,
                        'menu-item-url'       => $item_url,
                        'menu-item-object'    => $item->object,
                        'menu-item-object-id' => $object_id,
                        'menu-item-type'      => $item->type,
                        'menu-item-status'    => 'publish',
                        'menu-item-target'    => $item->target,
                        'menu-item-classes'   => implode(' ', (array) $item->classes),
                        'menu-item-xfn'       => $item->xfn,
                        'menu-item-position'  => $item->menu_order,
                    ];
                    if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                        $item_args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                    }
                    $new_item_id = wp_update_nav_menu_item($new_mid, 0, $item_args);
                    if (!is_wp_error($new_item_id)) {
                        $id_map[$item->db_id] = $new_item_id;
                    }
                }

                // Store in ID map
                if (!isset($menu_id_map[$src_menu_id])) {
                    $menu_id_map[$src_menu_id] = [];
                }
                $menu_id_map[$src_menu_id][$target_lang] = (int) $new_mid;

                $created_menus[] = [
                    'location' => '(no location)',
                    'menu_name' => $new_name,
                    'items' => count($src_items),
                    'skipped' => false,
                ];
            }

            update_option('aitrfoel_menu_id_map', $menu_id_map);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: %s: language code */ __('Menus translated and assigned for %s.', 'ai-translator-for-elementor-and-polylang'),
                    $lang_upper
                ),
                'menus' => $created_menus,
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate widget content for a target language.
     * Parses block widget markup and standard widget instance titles,
     * translates text, and stores in aitrfoel_widget_translations option.
     */
    public function handle_auto_translate_widgets() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
                throw new Exception(__('Built-in language system is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('Target language is required.', 'ai-translator-for-elementor-and-polylang'));
            }

            $builtin = AITRFOEL_Builtin_Integration::get_instance();
            $default_lang = $builtin->get_default_language();

            if ($target_lang === $default_lang) {
                throw new Exception(__('Cannot translate to the default language.', 'ai-translator-for-elementor-and-polylang'));
            }

            $api = AITRFOEL_OpenAI_API::get_instance();
            $sidebars = wp_get_sidebars_widgets();
            $translations = get_option('aitrfoel_widget_translations', []);
            $translated_count = 0;
            $texts_to_translate = [];
            $text_sources = []; // track where each text came from

            // Phase 1: collect all translatable texts
            foreach ($sidebars as $sidebar_id => $widgets) {
                if ($sidebar_id === 'wp_inactive_widgets' || empty($widgets)) {
                    continue;
                }

                foreach ($widgets as $widget_id) {
                    if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
                        continue;
                    }
                    $widget_type = $m[1];
                    $widget_number = (int) $m[2];

                    // Block widgets (type = "block")
                    if ($widget_type === 'block') {
                        $all_instances = get_option('widget_block', []);
                        if (isset($all_instances[$widget_number]['content'])) {
                            $content = $all_instances[$widget_number]['content'];
                            // Extract text from block markup
                            $block_texts = $this->extract_block_widget_texts($content);
                            foreach ($block_texts as $text) {
                                if (!in_array($text, $texts_to_translate, true)) {
                                    $texts_to_translate[] = $text;
                                    $text_sources[$text][] = ['widget_id' => $widget_id, 'type' => 'block'];
                                }
                            }
                        }
                    }

                    // Standard widgets with titles
                    $all_instances = get_option('widget_' . $widget_type, []);
                    if (isset($all_instances[$widget_number]['title']) && trim($all_instances[$widget_number]['title']) !== '') {
                        $title = trim($all_instances[$widget_number]['title']);
                        if (!in_array($title, $texts_to_translate, true)) {
                            $texts_to_translate[] = $title;
                        }
                        $text_sources[$title][] = ['widget_id' => $widget_id, 'type' => 'title', 'widget_type' => $widget_type, 'number' => $widget_number];
                    }

                    // Nav menu widgets — create a translated menu clone
                    if ($widget_type === 'nav_menu') {
                        $menu_id = isset($all_instances[$widget_number]['nav_menu']) ? intval($all_instances[$widget_number]['nav_menu']) : 0;
                        if ($menu_id > 0) {
                            $source_menu = wp_get_nav_menu_object($menu_id);
                            $menu_items = wp_get_nav_menu_items($menu_id);
                            if ($source_menu && $menu_items) {
                                // Collect labels for translation
                                foreach ($menu_items as $mi) {
                                    $label = trim($mi->title);
                                    if ($label !== '' && !in_array($label, $texts_to_translate, true)) {
                                        $texts_to_translate[] = $label;
                                    }
                                }
                                // Store source info for Phase 3 menu cloning
                                $text_sources['_nav_menu_widget_' . $widget_id] = [
                                    'menu_id' => $menu_id,
                                    'widget_id' => $widget_id,
                                ];
                            }
                        }
                    }
                }
            }

            if (empty($texts_to_translate)) {
                wp_send_json_success([
                    'message' => __('No translatable widget content found.', 'ai-translator-for-elementor-and-polylang'),
                    'translated' => 0,
                ]);
                return;
            }

            // Phase 2: batch translate
            $result = $api->translate_batch($texts_to_translate, $target_lang, $default_lang);
            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Phase 3: store translations keyed by widget_id and language
            foreach ($sidebars as $sidebar_id => $widgets) {
                if ($sidebar_id === 'wp_inactive_widgets' || empty($widgets)) {
                    continue;
                }

                foreach ($widgets as $widget_id) {
                    if (!preg_match('/^(.+)-(\d+)$/', $widget_id, $m)) {
                        continue;
                    }
                    $widget_type = $m[1];
                    $widget_number = (int) $m[2];

                    // Block widget content translation
                    if ($widget_type === 'block') {
                        $all_instances = get_option('widget_block', []);
                        if (isset($all_instances[$widget_number]['content'])) {
                            $content = $all_instances[$widget_number]['content'];
                            $translated_content = $this->replace_block_widget_texts($content, $result);
                            if ($translated_content !== $content) {
                                if (!isset($translations[$widget_id])) {
                                    $translations[$widget_id] = [];
                                }
                                $translations[$widget_id][$target_lang] = [
                                    'content' => $translated_content,
                                ];
                                $translated_count++;
                            }
                        }
                    }

                    // Widget title translation
                    $all_instances = get_option('widget_' . $widget_type, []);
                    if (isset($all_instances[$widget_number]['title']) && trim($all_instances[$widget_number]['title']) !== '') {
                        $original_title = trim($all_instances[$widget_number]['title']);
                        if (isset($result[$original_title])) {
                            if (!isset($translations[$widget_id])) {
                                $translations[$widget_id] = [];
                            }
                            if (!isset($translations[$widget_id][$target_lang])) {
                                $translations[$widget_id][$target_lang] = [];
                            }
                            $translations[$widget_id][$target_lang]['title'] = $result[$original_title];
                            $translated_count++;
                        }
                    }

                    // Nav menu widget — clone menu with translated labels and translated post links
                    if ($widget_type === 'nav_menu' && isset($text_sources['_nav_menu_widget_' . $widget_id])) {
                        $nav_source = $text_sources['_nav_menu_widget_' . $widget_id];
                        $source_menu_id = $nav_source['menu_id'];
                        $source_menu = wp_get_nav_menu_object($source_menu_id);
                        $source_items = wp_get_nav_menu_items($source_menu_id);
                        $lang_upper = strtoupper($target_lang);

                        if ($source_menu && $source_items) {
                            $new_menu_name = $source_menu->name . ' ' . $lang_upper;
                            $existing = wp_get_nav_menu_object($new_menu_name);
                            if ($existing) {
                                $new_menu_id = $existing->term_id;
                                $old_items = wp_get_nav_menu_items($new_menu_id);
                                if ($old_items) {
                                    foreach ($old_items as $old_item) {
                                        wp_delete_post($old_item->ID, true);
                                    }
                                }
                            } else {
                                $new_menu_id = wp_create_nav_menu($new_menu_name);
                                if (is_wp_error($new_menu_id)) {
                                    continue;
                                }
                            }

                            $id_map = [];
                            foreach ($source_items as $item) {
                                $translated_title = isset($result[$item->title]) ? $result[$item->title] : $item->title;
                                $object_id = $item->object_id;
                                $item_url = $item->url;
                                if (in_array($item->type, ['post_type', 'taxonomy'], true) && $object_id) {
                                    $post_translations = $builtin->get_post_translations($object_id);
                                    if (isset($post_translations[$target_lang])) {
                                        $object_id = $post_translations[$target_lang];
                                        $item_url = '';
                                    }
                                }
                                $args = [
                                    'menu-item-title'     => $translated_title,
                                    'menu-item-url'       => $item_url,
                                    'menu-item-object'    => $item->object,
                                    'menu-item-object-id' => $object_id,
                                    'menu-item-type'      => $item->type,
                                    'menu-item-status'    => 'publish',
                                    'menu-item-target'    => $item->target,
                                    'menu-item-classes'   => implode(' ', (array) $item->classes),
                                    'menu-item-xfn'       => $item->xfn,
                                    'menu-item-position'  => $item->menu_order,
                                ];
                                if ($item->menu_item_parent && isset($id_map[$item->menu_item_parent])) {
                                    $args['menu-item-parent-id'] = $id_map[$item->menu_item_parent];
                                }
                                $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);
                                if (!is_wp_error($new_item_id)) {
                                    $id_map[$item->db_id] = $new_item_id;
                                }
                            }

                            // Store translated menu ID for this widget
                            if (!isset($translations[$widget_id])) {
                                $translations[$widget_id] = [];
                            }
                            if (!isset($translations[$widget_id][$target_lang])) {
                                $translations[$widget_id][$target_lang] = [];
                            }
                            $translations[$widget_id][$target_lang]['nav_menu'] = $new_menu_id;
                            $translated_count++;
                        }
                    }
                }
            }

            update_option('aitrfoel_widget_translations', $translations);

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: target language, 2: number of widgets translated */ __('Widget content translated for %s: %d items.', 'ai-translator-for-elementor-and-polylang'),
                    strtoupper($target_lang),
                    $translated_count
                ),
                'translated' => $translated_count,
            ]);

        } catch (Exception $e) {
            $this->handle_error($e);
        }
    }

    /**
     * Auto-translate Elementor templates (headers, footers, sections) for a target language.
     * Called via AJAX from the Settings page.
     *
     * Supports two modes to avoid PHP max_execution_time timeouts:
     *
     * 1. list_only=1  — Returns the list of template IDs/labels that need translating
     *                   without actually translating anything. The JS uses this to build
     *                   a queue and then calls the handler once per template.
     *
     * 2. template_id=N — Translates exactly one template and returns the result.
     *                    The JS calls this in a sequential loop, one request per template.
     */
    public function handle_auto_translate_templates() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

            if (!current_user_can('manage_options')) {
                throw new Exception(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Large Elementor templates (e.g. review containers, loop grids) can
            // exceed the default execution time / memory and make the AJAX request
            // fail at the HTTP level ("request failed") rather than returning an
            // error. Give the translation more headroom.
            if (function_exists('wp_raise_memory_limit')) {
                wp_raise_memory_limit('admin');
            }
            // Best-effort: nudge the ceiling toward 512M for very large templates,
            // but ONLY raise it — never lower an already-higher host limit (which
            // could itself trigger a fatal). Silently ignored if the host blocks it
            // or memory is already unlimited (-1).
            if (function_exists('wp_convert_hr_to_bytes')) {
                $current_limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
                $target_limit  = 512 * 1024 * 1024;
                if ($current_limit !== -1 && $current_limit > 0 && $current_limit < $target_limit) {
                    @ini_set('memory_limit', '512M');
                }
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            @ignore_user_abort(true);

            $target_lang = sanitize_text_field($_POST['target_lang'] ?? '');
            if (empty($target_lang)) {
                throw new Exception(__('No target language specified.', 'ai-translator-for-elementor-and-polylang'));
            }

            $mode      = sanitize_text_field($_POST['mode'] ?? 'active');
            $force     = !empty($_POST['force']);
            $list_only = !empty($_POST['list_only']);
            $single_id = isset($_POST['template_id']) ? (int) $_POST['template_id'] : 0;

            if (!post_type_exists('elementor_library')) {
                throw new Exception(__('Elementor is not active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $multilingual = class_exists('AITRFOEL_Multilingual_Manager') ? AITRFOEL_Multilingual_Manager::get_instance() : null;
            if (!$multilingual || !$multilingual->is_active()) {
                throw new Exception(__('No multilingual system active.', 'ai-translator-for-elementor-and-polylang'));
            }

            $default_lang = $multilingual->get_default_language();

            // ── Single-template mode ────────────────────────────────────────────
            // JS passes template_id=N to translate exactly one template per request,
            // avoiding max_execution_time overruns when there are many templates.
            if ($single_id > 0) {
                $template = get_post($single_id);
                if (!$template || $template->post_type !== 'elementor_library') {
                    throw new Exception("Template #{$single_id} not found.");
                }

                $template_type = get_post_meta($single_id, '_elementor_template_type', true);
                $label = $template->post_title ?: ($template_type ?: 'Template #' . $single_id);

                $existing = $multilingual->get_post_translations($single_id);

                // Skip if already translated (unless force)
                if (!$force && isset($existing[$target_lang]) && $existing[$target_lang] != $single_id) {
                    wp_send_json_success([
                        'message'    => sprintf(/* translators: %s: item name */ __('⏭ %s — already translated', 'ai-translator-for-elementor-and-polylang'), $label),
                        'status'     => 'skipped',
                        'translated' => 0,
                        'skipped'    => 1,
                        'errors'     => 0,
                    ]);
                    return;
                }

                // Delete old translation if force re-translate
                if ($force && isset($existing[$target_lang]) && $existing[$target_lang] != $single_id) {
                    wp_delete_post((int) $existing[$target_lang], true);
                }

                $engine = AITRFOEL_Translation_Engine::get_instance();

                // Diagnostics: record the template's data size before we start, so
                // the plugin log shows whether this is an oversized template even if
                // a fatal kills the request before any response is returned.
                $elementor_len = strlen((string) get_post_meta($single_id, '_elementor_data', true));
                $content_len   = strlen((string) get_post_field('post_content', $single_id));
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log(
                        sprintf('Template translate start: #%d "%s" → %s | elementor_data=%dKB, content=%dKB, mem_limit=%s',
                            $single_id, $label, $target_lang, round($elementor_len / 1024), round($content_len / 1024), ini_get('memory_limit')),
                        'info', $single_id
                    );
                }

                // Surface uncatchable fatals (e.g. memory exhaustion on very large
                // templates) as a JSON error instead of a hard "request failed", and
                // record them to the plugin log so the cause is visible without WP_DEBUG.
                register_shutdown_function(function () use ($label, $single_id) {
                    $e = error_get_last();
                    if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true)) {
                        $msg = sprintf('Fatal while translating "%s": %s (%s:%d)', $label, $e['message'], basename($e['file']), $e['line']);
                        if (class_exists('AITRFOEL_Logger')) {
                            @AITRFOEL_Logger::log($msg, 'error', $single_id);
                        }
                        // Discard any partial output (notices/warnings) so the body is valid JSON.
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        if (!headers_sent()) {
                            header('Content-Type: application/json; charset=utf-8');
                            status_header(200);
                        }
                        echo wp_json_encode([
                            'success' => false,
                            'data'    => $msg,
                        ]);
                    }
                });

                // Capture and discard any stray output during translation. Elementor
                // regenerates page CSS on document save and can print it (atomic
                // container variables, etc.); if that leaks into the AJAX response it
                // corrupts the JSON and the browser reports a generic "request failed".
                ob_start();
                $result     = $engine->translate_page($single_id, $target_lang, $default_lang);
                $stray_out  = ob_get_clean();
                if (is_string($stray_out) && trim($stray_out) !== '' && class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log(
                        sprintf('Discarded %d bytes of stray output during template #%d translation (likely Elementor CSS).', strlen($stray_out), $single_id),
                        'info', $single_id
                    );
                }

                if (is_wp_error($result)) {
                    wp_send_json_success([
                        'message'    => sprintf(/* translators: 1: item name, 2: error message */ __('✗ %s — %s', 'ai-translator-for-elementor-and-polylang'), $label, $result->get_error_message()),
                        'status'     => 'error',
                        'translated' => 0,
                        'skipped'    => 0,
                        'errors'     => 1,
                    ]);
                } else {
                    wp_send_json_success([
                        'message'    => sprintf(/* translators: %s: item name */ __('✓ %s → translated', 'ai-translator-for-elementor-and-polylang'), $label),
                        'status'     => 'ok',
                        'translated' => 1,
                        'skipped'    => 0,
                        'errors'     => 0,
                    ]);
                }
                return;
            }

            // ── List / batch-queue mode ─────────────────────────────────────────
            // Build the list of templates that need to be (re)translated.

            $query_args = [
                'post_type'      => 'elementor_library',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => [
                    ['key' => '_elementor_data', 'compare' => 'EXISTS'],
                ],
            ];

            // Exclude already-translated (non-default) posts via taxonomy
            if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                $builtin     = AITRFOEL_Builtin_Integration::get_instance();
                $non_default = [];
                foreach ($builtin->get_available_languages() as $l) {
                    if ($l->code !== $default_lang) {
                        $non_default[] = $l->code;
                    }
                }
                if (!empty($non_default)) {
                    $query_args['tax_query'] = [[
                        'taxonomy' => 'aitrfoel_language',
                        'field'    => 'slug',
                        'terms'    => $non_default,
                        'operator' => 'NOT IN',
                    ]];
                }
            }

            $templates = get_posts($query_args);

            // PHP-level guard: confirm every post is actually the default language
            if (class_exists('AITRFOEL_Builtin_Integration') && AITRFOEL_Builtin_Integration::is_enabled()) {
                $bl_guard  = AITRFOEL_Builtin_Integration::get_instance();
                $templates = array_values(array_filter($templates, function($tpl) use ($bl_guard, $default_lang) {
                    return $bl_guard->get_post_language($tpl->ID) === $default_lang;
                }));
            }

            if (empty($templates)) {
                wp_send_json_success(['message' => __('No Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang'), 'queue' => []]);
                return;
            }

            // Filter templates by requested mode.
            if ($mode === 'active') {
                $templates = array_values(array_filter($templates, function($tpl) {
                    $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                    $tpl_type   = get_post_meta($tpl->ID, '_elementor_template_type', true);
                    return !empty($conditions) || in_array($tpl_type, ['header', 'footer'], true);
                }));
            } elseif ($mode === 'advanced') {
                $templates = array_values(array_filter($templates, function($tpl) {
                    $conditions = get_post_meta($tpl->ID, '_elementor_conditions', true);
                    $tpl_type   = get_post_meta($tpl->ID, '_elementor_template_type', true);
                    return empty($conditions) && !in_array($tpl_type, ['header', 'footer'], true);
                }));
            }

            if (empty($templates)) {
                $empty_message = __('No active Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang');
                if ($mode === 'advanced') {
                    $empty_message = __('No advanced Elementor templates found to translate.', 'ai-translator-for-elementor-and-polylang');
                }
                wp_send_json_success(['message' => $empty_message, 'queue' => []]);
                return;
            }

            // Build queue: skip already-translated unless force
            $queue = [];
            foreach ($templates as $tpl) {
                $existing = $multilingual->get_post_translations($tpl->ID);
                $already  = isset($existing[$target_lang]) && $existing[$target_lang] != $tpl->ID;

                if (!$force && $already) {
                    continue; // will be skipped — don't add to queue
                }

                $tpl_type = get_post_meta($tpl->ID, '_elementor_template_type', true);
                $queue[] = [
                    'id'    => $tpl->ID,
                    'label' => $tpl->post_title ?: ($tpl_type ?: 'Template #' . $tpl->ID),
                    'type'  => $tpl_type,
                ];
            }

            // list_only=1: just return the queue, don't translate anything
            if ($list_only) {
                $skipped_count = count($templates) - count($queue);
                wp_send_json_success([
                    'message' => sprintf(
                        /* translators: 1: number of templates to translate, 2: number already translated */ __('%d template(s) to translate, %d already done.', 'ai-translator-for-elementor-and-polylang'),
                        count($queue),
                        $skipped_count
                    ),
                    'queue'   => $queue,
                    'skipped' => $skipped_count,
                ]);
                return;
            }

            // Legacy fallback: no template_id and no list_only — translate all in one go
            // (kept for backward-compat but the JS now uses the chunked path above)
            $engine     = AITRFOEL_Translation_Engine::get_instance();
            $translated = 0;
            $skipped    = 0;
            $errors     = 0;
            $details    = [];

            foreach ($templates as $template) {
                $template_type = get_post_meta($template->ID, '_elementor_template_type', true);
                $label         = $template->post_title ?: ($template_type ?: 'Template #' . $template->ID);

                $existing = $multilingual->get_post_translations($template->ID);
                if (!$force && isset($existing[$target_lang]) && $existing[$target_lang] != $template->ID) {
                    $details[] = sprintf(/* translators: %s: template name */ __('⏭ %s — already translated', 'ai-translator-for-elementor-and-polylang'), $label);
                    $skipped++;
                    continue;
                }

                if ($force && isset($existing[$target_lang]) && $existing[$target_lang] != $template->ID) {
                    wp_delete_post((int) $existing[$target_lang], true);
                    $details[] = sprintf(/* translators: %s: template name */ __('%s — re-translating (old translation removed)', 'ai-translator-for-elementor-and-polylang'), $label);
                }

                $result = $engine->translate_page($template->ID, $target_lang, $default_lang);

                if (is_wp_error($result)) {
                    $details[] = sprintf(/* translators: 1: template name, 2: error message */ __('✗ %s — %s', 'ai-translator-for-elementor-and-polylang'), $label, $result->get_error_message());
                    $errors++;
                } else {
                    $details[] = sprintf(/* translators: %s: template name */ __('✓ %s → translated', 'ai-translator-for-elementor-and-polylang'), $label);
                    $translated++;
                }
            }

            wp_send_json_success([
                'message' => sprintf(
                    /* translators: 1: target language, 2: number translated, 3: number skipped, 4: number of errors */ __('Elementor templates for %s: %d translated, %d skipped, %d errors.', 'ai-translator-for-elementor-and-polylang'),
                    strtoupper($target_lang),
                    $translated,
                    $skipped,
                    $errors
                ),
                'translated' => $translated,
                'skipped'    => $skipped,
                'errors'     => $errors,
                'details'    => $details,
            ]);

        } catch (\Throwable $e) {
            // \Throwable also catches Error/TypeError fatals (not just Exception),
            // so a coding-level fault in one template returns a readable message
            // instead of failing the whole AJAX request.
            $this->handle_error($e);
        }
    }

    /**
     * Extract visible text from block widget HTML content.
     */
    private function extract_block_widget_texts($html) {
        $texts = [];
        // Strip block comments
        $stripped = preg_replace('/<!--.*?-->/', '', $html);
        // Match text content inside HTML tags
        if (preg_match_all('/>([^<]+)</', $stripped, $matches)) {
            foreach ($matches[1] as $text) {
                $text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
                // Skip empty, numbers-only, or very short strings
                if ($text !== '' && !preg_match('/^[\d\s\.\,\-\+\%\$\€\£\¥\#\@\!\?]+$/', $text) && mb_strlen($text) >= 2) {
                    $texts[] = $text;
                }
            }
        }
        return array_unique($texts);
    }

    /**
     * Replace original texts in block widget HTML with translated versions.
     */
    private function replace_block_widget_texts($html, $translations) {
        foreach ($translations as $original => $translated) {
            if ($original === $translated || empty($translated)) {
                continue;
            }
            // Replace inside tag content, preserving HTML structure
            $escaped_original = preg_quote(htmlspecialchars($original, ENT_QUOTES, 'UTF-8'), '/');
            $html = preg_replace(
                '/(>)(' . $escaped_original . ')(<)/',
                '$1' . htmlspecialchars($translated, ENT_QUOTES, 'UTF-8') . '$3',
                $html
            );
            // Also try with the raw text (not HTML-encoded)
            $escaped_raw = preg_quote($original, '/');
            $html = preg_replace(
                '/(>)(' . $escaped_raw . ')(<)/',
                '$1' . $translated . '$3',
                $html
            );
        }
        return $html;
    }

    // =========================================================================
    // SEO META PATCH TOOL
    // =========================================================================

    /**
     * Return total count of translated posts (non-default language) that can be patched.
     */
    public function handle_seo_patch_count() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        global $wpdb;
        $table        = $wpdb->prefix . AITRFOEL_Builtin_Integration::TABLE_NAME;
        $default_lang = AITRFOEL_Builtin_Integration::get_instance()->get_default_language();

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} t
             JOIN {$table} s ON s.group_id = t.group_id AND s.language_code = %s
             WHERE t.language_code != %s",
            $default_lang, $default_lang
        ));

        wp_send_json_success(['total' => $total]);
    }

    /**
     * Process one batch of SEO meta patching.
     * Expects POST: offset (int), per_page (int, default 20).
     */
    public function handle_seo_patch_batch() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        $offset   = max(0, (int) ($_POST['offset']   ?? 0));
        $per_page = min(50, max(1, (int) ($_POST['per_page'] ?? 20)));

        global $wpdb;
        $table        = $wpdb->prefix . AITRFOEL_Builtin_Integration::TABLE_NAME;
        $default_lang = AITRFOEL_Builtin_Integration::get_instance()->get_default_language();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.post_id AS target_id, t.language_code AS target_lang, s.post_id AS source_id
             FROM {$table} t
             JOIN {$table} s ON s.group_id = t.group_id AND s.language_code = %s
             WHERE t.language_code != %s
             ORDER BY t.post_id
             LIMIT %d OFFSET %d",
            $default_lang, $default_lang, $per_page, $offset
        ));

        if (!class_exists('AITRFOEL_Translation_Engine')) {
            wp_send_json_error('Translation engine not available.');
        }

        $engine    = AITRFOEL_Translation_Engine::get_instance();
        $processed = 0;

        foreach ($rows as $row) {
            $engine->patch_seo_meta_fields(
                (int) $row->source_id,
                (int) $row->target_id,
                $row->target_lang
            );
            $processed++;
        }

        wp_send_json_success([
            'processed'   => $processed,
            'next_offset' => $offset + $processed,
            'done'        => ($processed < $per_page),
        ]);
    }

    /**
     * Count product translation pairs that have a non-empty excerpt to retranslate.
     */
    public function handle_excerpt_patch_count() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        global $wpdb;
        $table        = $wpdb->prefix . AITRFOEL_Builtin_Integration::TABLE_NAME;
        $default_lang = AITRFOEL_Builtin_Integration::get_instance()->get_default_language();

        // Count translated product pairs (source OR target has a non-empty excerpt)
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$table} t
             JOIN {$table} s ON s.group_id = t.group_id AND s.language_code = %s
             JOIN {$wpdb->posts} p ON p.ID = t.post_id AND p.post_type = 'product' AND p.post_status != 'trash'
             WHERE t.language_code != %s",
            $default_lang, $default_lang
        ));

        wp_send_json_success(['total' => $total]);
    }

    /**
     * Process one batch of product excerpt retranslations.
     * Uses source excerpt when available; falls back to translating the target's
     * existing excerpt (handles cases where the excerpt was copied verbatim to
     * the translated product without being translated).
     */
    public function handle_excerpt_patch_batch() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        $offset   = max(0, (int) ($_POST['offset']   ?? 0));
        $per_page = min(20, max(1, (int) ($_POST['per_page'] ?? 10)));

        global $wpdb;
        $table        = $wpdb->prefix . AITRFOEL_Builtin_Integration::TABLE_NAME;
        $default_lang = AITRFOEL_Builtin_Integration::get_instance()->get_default_language();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.post_id AS target_id, t.language_code AS target_lang, s.post_id AS source_id
             FROM {$table} t
             JOIN {$table} s ON s.group_id = t.group_id AND s.language_code = %s
             JOIN {$wpdb->posts} p ON p.ID = t.post_id AND p.post_type = 'product' AND p.post_status != 'trash'
             WHERE t.language_code != %s
             ORDER BY t.post_id
             LIMIT %d OFFSET %d",
            $default_lang, $default_lang, $per_page, $offset
        ));

        $api       = AITRFOEL_OpenAI_API::get_instance();
        $processed = 0;
        $skipped   = 0;
        $force     = !empty($_POST['force']);

        foreach ($rows as $row) {
            $target_id   = (int) $row->target_id;
            $source_post = get_post((int) $row->source_id);
            $target_post = get_post($target_id);
            $source_exc  = $source_post ? trim($source_post->post_excerpt) : '';
            $target_exc  = $target_post ? trim($target_post->post_excerpt) : '';

            // Prefer translating from source; fall back to target's existing text
            // (covers the case where excerpt was copied verbatim instead of translated)
            $text = !empty($source_exc) ? $source_exc : $target_exc;

            if (!empty($text)) {
                // Idempotency: skip if this exact source text + language was already
                // translated on a previous run (unless "force" is requested). This
                // avoids needless API calls and text drift on repeated runs, while
                // still re-translating when the source text actually changes.
                $signature = md5($text . '|' . $row->target_lang);
                $previous  = get_post_meta($target_id, '_aitrfoel_excerpt_sig', true);

                if (!$force && $previous === $signature && $target_exc !== '') {
                    $skipped++;
                    $processed++;
                    continue;
                }

                $translated = $api->translate_text($text, $row->target_lang, $default_lang);
                if (!is_wp_error($translated) && !empty(trim((string) $translated))) {
                    wp_update_post(['ID' => $target_id, 'post_excerpt' => $translated]);
                    update_post_meta($target_id, '_aitrfoel_excerpt_sig', $signature);
                }
            }
            $processed++;
        }

        wp_send_json_success([
            'processed'   => $processed,
            'skipped'     => $skipped,
            'next_offset' => $offset + $processed,
            'done'        => ($processed < $per_page),
        ]);
    }

    /**
     * Check how many existing translated posts have SEO titles that need retranslation.
     * A post "needs retranslation" if its rank_math_title (or _yoast_wpseo_title as fallback)
     * is empty or identical to the source post's value.
     */
    public function handle_seo_check() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            wp_send_json_error('Built-in language system not active.');
        }

        global $wpdb;
        $table        = $wpdb->prefix . AITRFOEL_Builtin_Integration::TABLE_NAME;
        $pm           = $wpdb->postmeta;
        $default_lang = AITRFOEL_Builtin_Integration::get_instance()->get_default_language();

        $check_key = class_exists('RankMath') || defined('RANK_MATH_VERSION')
            ? 'rank_math_title'
            : '_yoast_wpseo_title';

        // Subquery groups by post first to avoid duplicate postmeta rows inflating COUNT(*).
        // MAX(meta_value) collapses any duplicate meta entries into one value per post.
        // Template-variable titles (containing '%%') are language-neutral — if source=target that is OK.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE
                    -- Source has a custom title AND target is missing, empty, or identical
                    -- BUT only flag as needing retranslation if it's a literal title (no %% variables)
                    WHEN sm_val IS NOT NULL AND sm_val != ''
                         AND (
                             tm_val IS NULL OR tm_val = ''
                             OR (tm_val = sm_val AND LOCATE('%%', sm_val) = 0)
                         )
                    THEN 1 ELSE 0
                END) AS needs_retranslation,
                SUM(CASE
                    -- Source has no custom title (Rank Math uses post title = fine)
                    WHEN (sm_val IS NULL OR sm_val = '')
                    THEN 1
                    -- Source uses template variables (%%title%% etc.) — same value in target is correct
                    WHEN sm_val IS NOT NULL AND sm_val != ''
                         AND tm_val = sm_val AND LOCATE('%%', sm_val) > 0
                    THEN 1
                    -- Source has a literal title AND target has a different literal title = translated
                    WHEN sm_val IS NOT NULL AND sm_val != ''
                         AND tm_val IS NOT NULL AND tm_val != ''
                         AND tm_val != sm_val
                    THEN 1
                    ELSE 0
                END) AS ok
             FROM (
                 SELECT t.post_id,
                        MAX(sm.meta_value) AS sm_val,
                        MAX(tm.meta_value) AS tm_val
                 FROM {$table} t
                 JOIN {$table} s  ON s.group_id = t.group_id AND s.language_code = %s
                 LEFT JOIN {$pm} tm ON tm.post_id = t.post_id AND tm.meta_key = %s
                 LEFT JOIN {$pm} sm ON sm.post_id = s.post_id AND sm.meta_key = %s
                 WHERE t.language_code != %s
                 GROUP BY t.post_id, s.post_id
             ) AS per_post",
            $default_lang, $check_key, $check_key, $default_lang
        ));

        // If no Rank Math data found at all, try Yoast as fallback
        if ($row && (int) $row->total > 0 && (int) $row->ok === 0 && $check_key === 'rank_math_title') {
            $row_yoast = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE
                        WHEN sm_val IS NOT NULL AND sm_val != ''
                             AND (
                                 tm_val IS NULL OR tm_val = ''
                                 OR (tm_val = sm_val AND LOCATE('%%', sm_val) = 0)
                             )
                        THEN 1 ELSE 0
                    END) AS needs_retranslation,
                    SUM(CASE
                        WHEN (sm_val IS NULL OR sm_val = '')
                        THEN 1
                        WHEN sm_val IS NOT NULL AND sm_val != ''
                             AND tm_val = sm_val AND LOCATE('%%', sm_val) > 0
                        THEN 1
                        WHEN sm_val IS NOT NULL AND sm_val != ''
                             AND tm_val IS NOT NULL AND tm_val != ''
                             AND tm_val != sm_val
                        THEN 1
                        ELSE 0
                    END) AS ok
                 FROM (
                     SELECT t.post_id,
                            MAX(sm.meta_value) AS sm_val,
                            MAX(tm.meta_value) AS tm_val
                     FROM {$table} t
                     JOIN {$table} s  ON s.group_id = t.group_id AND s.language_code = %s
                     LEFT JOIN {$pm} tm ON tm.post_id = t.post_id AND tm.meta_key = '_yoast_wpseo_title'
                     LEFT JOIN {$pm} sm ON sm.post_id = s.post_id AND sm.meta_key = '_yoast_wpseo_title'
                     WHERE t.language_code != %s
                     GROUP BY t.post_id, s.post_id
                 ) AS per_post",
                $default_lang, $default_lang
            ));
            if ($row_yoast && (int) $row_yoast->ok > 0) {
                $row = $row_yoast;
            }
        }

        wp_send_json_success([
            'total'               => $row ? (int) $row->total               : 0,
            'ok'                  => $row ? (int) $row->ok                  : 0,
            'needs_retranslation' => $row ? (int) $row->needs_retranslation : 0,
        ]);
    }

    /**
     * AJAX handler to scan or localize all media attachments in the Media Library for all target languages.
     */
    public function handle_localize_media_library() {
        try {
            check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_send_json_error(__('Insufficient permissions.', 'ai-translator-for-elementor-and-polylang'));
            }

            $scan_only = !empty($_POST['scan']);
            $multilingual = AITRFOEL_Multilingual_Manager::get_instance();
            $available_langs = $multilingual->get_available_languages();
            $default_lang = $multilingual->get_default_language();

            $target_langs = [];
            foreach ($available_langs as $l) {
                $code = is_object($l) ? $l->code : (is_array($l) ? $l['code'] : (string)$l);
                if ($code && $code !== $default_lang) {
                    $target_langs[] = $code;
                }
            }

            if (empty($target_langs)) {
                wp_send_json_error(__('No target languages configured.', 'ai-translator-for-elementor-and-polylang'));
            }

            // Get all media attachments
            $attachments = get_posts([
                'post_type'        => 'attachment',
                'post_status'      => 'inherit',
                'posts_per_page'   => -1,
                'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
            ]);

            $source_attachments = [];
            foreach ($attachments as $att) {
                // Skip if this is already a translated attachment copy
                $is_copy = get_post_meta($att->ID, '_aitrfoel_source_attachment', true);
                if ($is_copy) {
                    continue;
                }
                $lang = $multilingual->get_post_language($att->ID);
                if ($lang && $lang !== $default_lang) {
                    continue;
                }
                $source_attachments[] = $att;
            }

            $total_sources = count($source_attachments);
            $total_to_create = 0;
            $items_to_process = [];

            foreach ($source_attachments as $att) {
                $translations = $multilingual->get_post_translations($att->ID);
                if (!is_array($translations)) {
                    $translations = [];
                }
                foreach ($target_langs as $tlang) {
                    if (!isset($translations[$tlang]) || !$translations[$tlang]) {
                        $total_to_create++;
                        if (!$scan_only) {
                            $items_to_process[] = ['id' => $att->ID, 'lang' => $tlang];
                        }
                    }
                }
            }

            if ($scan_only) {
                wp_send_json_success([
                    'total_sources'   => $total_sources,
                    'total_to_create' => $total_to_create,
                    'scan'            => true,
                ]);
            }

            // Execute localization
            $engine = AITRFOEL_Translation_Engine::get_instance();
            $created_count = 0;

            foreach ($items_to_process as $item) {
                $new_id = $engine->localize_attachment($item['id'], $item['lang'], $default_lang);
                if ($new_id && $new_id !== $item['id']) {
                    $created_count++;
                }
            }

            wp_send_json_success([
                'total_sources' => $total_sources,
                'created'       => $created_count,
                'scan'          => false,
            ]);

        } catch (\Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * AJAX handler to detect website context, industry, and suggest optimal Brand Voice / Tone preset
     */
    public function handle_detect_site_tone() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized permission.', 'ai-translator-for-elementor-and-polylang')]);
        }

        // 1. Collect site information
        $site_name = get_bloginfo('name');
        $site_desc = get_bloginfo('description');
        
        $front_page_id = (int) get_option('page_on_front');
        $sample_text = $site_name . ' ' . $site_desc;

        if ($front_page_id > 0) {
            $front_post = get_post($front_page_id);
            if ($front_post) {
                $sample_text .= ' ' . $front_post->post_title . ' ' . wp_strip_all_tags($front_post->post_content);
            }
        } else {
            // Get latest post
            $recent = get_posts(['numberposts' => 1, 'post_status' => 'publish']);
            if (!empty($recent)) {
                $sample_text .= ' ' . $recent[0]->post_title . ' ' . wp_strip_all_tags($recent[0]->post_content);
            }
        }

        // Check if WooCommerce or SureCart or eCommerce active
        $has_ecommerce = class_exists('WooCommerce') || class_exists('\SureCart\SureCart');

        $sample_lower = function_exists('mb_strtolower') ? mb_strtolower($sample_text, 'UTF-8') : strtolower($sample_text);

        // Keywords dictionaries for preset classification
        $keywords_hospitality = ['hotel', 'stay', 'room', 'resort', 'spa', 'massage', 'restaurant', 'food', 'travel', 'tour', 'retreat', 'wellness', 'vacation', 'holiday', 'cafe', 'cottage', 'cabin', 'stuga', 'booking', 'guest', 'hospitality', 'lodge', 'suite'];
        $keywords_corporate = ['software', 'saas', 'enterprise', 'consulting', 'financial', 'finance', 'legal', 'law', 'agency', 'b2b', 'platform', 'solutions', 'management', 'analytics', 'compliance', 'invest', 'technology', 'cloud', 'security', 'infrastructure', 'advisory', 'corporate', 'partner'];
        $keywords_sales = ['shop', 'store', 'cart', 'checkout', 'discount', 'deal', 'sale', 'product', 'buy', 'offer', 'shipping', 'brand', 'collection', 'clothing', 'fashion', 'order', 'apparel', 'dtc', 'ecommerce'];
        $keywords_casual = ['blog', 'creator', 'lifestyle', 'podcast', 'community', 'tips', 'hobby', 'gaming', 'personal', 'vlog', 'stories', 'guides', 'art', 'music'];

        $scores = [
            'hospitality' => 0,
            'corporate'   => 0,
            'sales'       => $has_ecommerce ? 3 : 0,
            'casual'      => 0,
        ];

        $matched_keywords = [];

        foreach ($keywords_hospitality as $kw) {
            if (strpos($sample_lower, $kw) !== false) {
                $scores['hospitality'] += 2;
                $matched_keywords[] = $kw;
            }
        }
        foreach ($keywords_corporate as $kw) {
            if (strpos($sample_lower, $kw) !== false) {
                $scores['corporate'] += 2;
                $matched_keywords[] = $kw;
            }
        }
        foreach ($keywords_sales as $kw) {
            if (strpos($sample_lower, $kw) !== false) {
                $scores['sales'] += 2;
                $matched_keywords[] = $kw;
            }
        }
        foreach ($keywords_casual as $kw) {
            if (strpos($sample_lower, $kw) !== false) {
                $scores['casual'] += 2;
                $matched_keywords[] = $kw;
            }
        }

        // Determine top recommendation
        arsort($scores);
        $top_preset = key($scores);
        $max_score = current($scores);

        if ($max_score <= 1) {
            $top_preset = $has_ecommerce ? 'sales' : 'corporate';
        }

        $preset_labels = [
            'hospitality' => __('Warm & Welcoming (Hospitality & Lifestyle)', 'ai-translator-for-elementor-and-polylang'),
            'corporate'   => __('Professional & Corporate (B2B & Tech)', 'ai-translator-for-elementor-and-polylang'),
            'sales'       => __('Persuasive & Sales-Driven (eCommerce & DTC)', 'ai-translator-for-elementor-and-polylang'),
            'casual'      => __('Casual & Modern (Blogs & Creators)', 'ai-translator-for-elementor-and-polylang'),
        ];

        wp_send_json_success([
            'suggested_preset' => $top_preset,
            'preset_label'     => $preset_labels[$top_preset] ?? $top_preset,
            'site_name'        => $site_name,
            'matched_keywords' => array_values(array_unique(array_slice($matched_keywords, 0, 8))),
            'has_ecommerce'    => $has_ecommerce,
            'explanation'      => sprintf(
                /* translators: 1: site name, 2: suggested brand voice */ __('Detected site context for "%s". Suggested Brand Voice: %s.', 'ai-translator-for-elementor-and-polylang'),
                esc_html($site_name),
                $preset_labels[$top_preset] ?? $top_preset
            ),
        ]);
    }

    /**
     * Dismiss limit reached admin notice for 7 days
     */
    public function handle_dismiss_limit_notice() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        set_transient('aitrfoel_limit_notice_dismissed_' . get_current_user_id(), true, 7 * DAY_IN_SECONDS);
        wp_send_json_success();
    }

    /**
     * Claim +2,500 bonus words for social sharing (100% WordPress.org compliant)
     */
    public function handle_claim_share_bonus() {
        check_ajax_referer('aitrfoel_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to perform this action.', 'ai-translator-for-elementor-and-polylang'));
        }

        $platform = isset($_POST['platform']) ? sanitize_text_field(wp_unslash($_POST['platform'])) : 'x';
        $site_url = home_url();

        // Check if already claimed locally
        $already_claimed = get_option('aitrfoel_share_bonus_claimed', false);
        if ($already_claimed) {
            wp_send_json_error([
                'message' => __('Bonus words have already been claimed for this website.', 'ai-translator-for-elementor-and-polylang'),
                'claimed' => true,
            ]);
        }

        // Contact MeepTech server to register bonus words
        $base_url = class_exists('AITRFOEL_License_Manager_Server')
            ? AITRFOEL_License_Manager_Server::get_instance()->get_api_base_url()
            : 'https://api.meeptech.com/api/eat';
        $server_url = rtrim($base_url, '/') . '/claim-bonus-words';
        $payload = wp_json_encode([
            'site_url'  => $site_url,
            'platform'  => $platform,
            'client_ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ]);
        $req_args = [
            'timeout' => 15,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => $payload,
        ];

        $response = wp_remote_post($server_url, $req_args);
        $status_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

        // Fallback to primary meeptech.com domain if api subdomain fails or 404s
        if (is_wp_error($response) || $status_code < 200 || $status_code >= 300) {
            $fallback_url = 'https://meeptech.com/api/eat/claim-bonus-words';
            if ($server_url !== $fallback_url) {
                $response = wp_remote_post($fallback_url, $req_args);
                $status_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            }
        }

        if (is_wp_error($response) || $status_code < 200 || $status_code >= 300) {
            wp_send_json_error([
                'message' => __('Could not reach the bonus server. Please try again in a moment.', 'ai-translator-for-elementor-and-polylang'),
            ]);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['success']) && empty($body['claimed']) && empty($body['already_claimed'])) {
            wp_send_json_error([
                'message' => isset($body['error']) ? sanitize_text_field($body['error']) : __('Could not claim bonus words. Please try again.', 'ai-translator-for-elementor-and-polylang'),
            ]);
        }

        // Mark as claimed locally and clear any active cooldown / stale cache
        update_option('aitrfoel_share_bonus_claimed', time());
        update_option('aitrfoel_share_bonus_platform', $platform);
        delete_option('aitrfoel_cooldown_end_date');
        delete_transient('aitrfoel_license_status_cache');
        delete_transient('aitrfoel_license_usage_cache');
        delete_transient('aitrfoel_remote_usage_data');
        delete_transient('aitrfoel_limit_100_dismissed');

        if (class_exists('AITRFOEL_License_Manager_Server')) {
            AITRFOEL_License_Manager_Server::get_instance()->get_license_status(true);
        }

        wp_send_json_success([
            'message'        => __('+2,500 bonus words successfully added to your account! Happy translating.', 'ai-translator-for-elementor-and-polylang'),
            'bonus_words'    => isset($body['bonus_words']) ? (int) $body['bonus_words'] : 2500,
            'new_word_limit' => isset($body['new_word_limit']) ? (int) $body['new_word_limit'] : null,
            'platform'       => $platform,
        ]);
    }

}
