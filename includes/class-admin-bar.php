<?php
/**
 * Global WP Admin Bar Hub Class
 * Adds a persistent Meep AI Translator hub menu in the top WordPress admin bar
 * Shows live word usage balance, limit status, health meter score, and quick actions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Admin_Bar {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 90);
        add_action('wp_head', [$this, 'render_admin_bar_css']);
        add_action('admin_head', [$this, 'render_admin_bar_css']);
    }

    /**
     * Build top admin bar node & dropdown items
     */
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!is_admin_bar_showing() || !current_user_can('edit_posts')) {
            return;
        }

        // Detect single page / post context
        $post_id = 0;
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->base === 'post' && isset($_GET['post'])) {
                $post_id = absint($_GET['post']);
            } elseif (isset($GLOBALS['post']->ID)) {
                $post_id = absint($GLOBALS['post']->ID);
            }
        } else {
            $post_id = get_queried_object_id();
            if (!$post_id && (is_front_page() || is_home())) {
                $post_id = absint(get_option('page_on_front'));
            }
            if (!$post_id && isset($GLOBALS['post']->ID)) {
                $post_id = absint($GLOBALS['post']->ID);
            }
        }

        $has_page_context = false;
        $page_lang_data   = null;

        if ($post_id && (current_user_can('edit_post', $post_id) || current_user_can('manage_options'))) {
            $multilingual = class_exists('AITRFOEL_Multilingual_Manager')
                ? AITRFOEL_Multilingual_Manager::get_instance()
                : null;

            if ($multilingual && $multilingual->is_active() && $multilingual->has_languages_configured()) {
                $available_langs = $multilingual->get_available_languages();
                if (count($available_langs) >= 2) {
                    $has_page_context = true;
                    $post_lang        = $multilingual->get_post_language($post_id);
                    $translations     = $multilingual->get_post_translations($post_id);
                    $default_lang     = $multilingual->get_default_language();
                    $source_id        = !empty($translations[$default_lang]) ? absint($translations[$default_lang]) : $post_id;
                    $is_source        = ($post_id === $source_id) || ($post_lang === $default_lang);

                    $default_lang_name = strtoupper($default_lang);
                    if (isset($available_langs[$default_lang])) {
                        $dl = $available_langs[$default_lang];
                        $default_lang_name = is_object($dl) ? ($dl->name ?? strtoupper($default_lang)) : (is_array($dl) ? ($dl['name'] ?? strtoupper($default_lang)) : strtoupper($default_lang));
                    }

                    $current_url = is_admin() 
                        ? (isset($_SERVER['REQUEST_URI']) ? home_url($_SERVER['REQUEST_URI']) : admin_url('post.php?post=' . $post_id . '&action=edit'))
                        : get_permalink($post_id);

                    $page_lang_data = [
                        'post_id'           => $post_id,
                        'source_id'         => $source_id,
                        'is_source'         => $is_source,
                        'post_lang'         => $post_lang,
                        'default_lang'      => $default_lang,
                        'default_lang_name' => $default_lang_name,
                        'translations'      => $translations,
                        'available_langs'   => $available_langs,
                        'current_url'       => $current_url,
                    ];
                }
            }
        }

        // Get live license & word usage status
        $license_manager = class_exists('AITRFOEL_License_Manager_Server')
            ? AITRFOEL_License_Manager_Server::get_instance()
            : null;

        $license_status = $license_manager ? $license_manager->get_license_status() : [];
        $used           = isset($license_status['words_used']) ? (int) $license_status['words_used'] : (int) ($license_status['words_translated'] ?? 0);
        $raw_limit      = isset($license_status['word_limit']) ? (int) $license_status['word_limit'] : (int) ($license_status['total_words'] ?? 7200);
        $plan_name      = !empty($license_status['plan_name']) ? $license_status['plan_name'] : (!empty($license_status['plan']) ? ucfirst($license_status['plan']) : 'Free');
        $is_unlimited   = ($raw_limit === -1 || !empty($license_status['unlimited']) || !empty($license_status['is_unlimited']) || strtolower($plan_name) === 'agency lifetime');
        $limit          = $is_unlimited ? -1 : ($raw_limit <= 0 ? 7200 : $raw_limit);
        $is_pro         = (defined('AITRFOEL_VERSION_TYPE') && AITRFOEL_VERSION_TYPE === 'pro') || ($plan_name !== 'Free' && $plan_name !== 'free');
        
        $pct            = ($limit > 0 && !$is_unlimited) ? min(100, round(($used / $limit) * 100)) : 0;
        $is_limit_reached = (!$is_unlimited && $pct >= 100);

        // Calculate site translation completeness
        $score = 0;
        $total_pages = 0;
        $translated_pages = 0;
        if (class_exists('AITRFOEL_Admin_Menu')) {
            $admin_menu = AITRFOEL_Admin_Menu::get_instance();
            if (method_exists($admin_menu, 'get_translation_stats')) {
                $stats = $admin_menu->get_translation_stats();
                $score = $stats['score'] ?? 0;
                $total_pages = $stats['total'] ?? 0;
                $translated_pages = $stats['translated'] ?? 0;
            }
        }

        $dashboard_url   = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang');
        $translate_all_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-translate-all');
        $settings_url    = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang-settings');
        $pricing_url     = aitrfoel_free_upgrade_url('admin-bar');

        // 1. Primary Top Node
        $badge_html = '';
        if ($is_limit_reached) {
            $badge_html = '<span class="aitrfoel-bar-pill aitrfoel-pill-danger">' . esc_html__('Limit Reached', 'ai-translator-for-elementor-and-polylang') . '</span>';
        } elseif ($pct >= 80) {
            $badge_html = '<span class="aitrfoel-bar-pill aitrfoel-pill-warning">' . esc_html(sprintf(/* translators: %d: percentage of the site translated */ __('%d%%', 'ai-translator-for-elementor-and-polylang'), $pct)) . '</span>';
        }

        $icon_svg = '<span class="ab-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 44.68 29.3" width="18" height="12" style="display:inline-block;vertical-align:middle;"><path fill="currentColor" d="M0 29.3l7.84 0 0 -16.56c0,-5.02 3.39,-5.9 5.21,-5.9 1.82,0 5.34,0.88 5.34,5.9l0 16.56 7.84 0 0 -16.56c0,-5.02 3.45,-5.9 5.21,-5.9 1.82,0 5.39,0.88 5.39,5.9l0 16.56 7.85 0 0 -17.63c0,-9.04 -5.71,-11.67 -10.8,-11.67 -3.2,0 -7.02,1.07 -9.47,4.39 -2.07,-3.26 -5.58,-4.39 -8.91,-4.39 -2.64,0 -5.52,1.01 -7.66,3.33l0 -2.89 -7.84 0 0 28.86 0 0z"/></svg></span>';

        $node_label = $has_page_context 
            ? esc_html__('Meep Translate', 'ai-translator-for-elementor-and-polylang')
            : esc_html__('Meep', 'ai-translator-for-elementor-and-polylang');

        $wp_admin_bar->add_node([
            'id'    => 'aitrfoel-admin-bar-hub',
            'title' => $icon_svg . '<span class="ab-label">' . $node_label . '</span>' . $badge_html,
            'href'  => $dashboard_url,
            'meta'  => [
                'title' => esc_attr__('Meep Translator Hub', 'ai-translator-for-elementor-and-polylang'),
                'class' => 'aitrfoel-admin-bar-root',
            ],
        ]);

        // 2. Single Page Translation Submenu Items
        if ($has_page_context && $page_lang_data) {
            $pld = $page_lang_data;

            if ($pld['is_source']) {
                foreach ($pld['available_langs'] as $code => $lang) {
                    if ($code === $pld['post_lang'] || $code === $pld['default_lang']) {
                        continue;
                    }

                    $lang_name = is_object($lang)
                        ? ($lang->name ?? strtoupper($code))
                        : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

                    $has_translation = isset($pld['translations'][$code]) && $pld['translations'][$code];
                    $trans_post_id   = $has_translation ? absint($pld['translations'][$code]) : 0;
                    $flag_html       = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_html($code, 16, 'margin-right:6px;vertical-align:middle;') : '';

                    if ($has_translation && $trans_post_id) {
                        $retrans_url = wp_nonce_url(
                            add_query_arg([
                                'action'      => 'aitrfoel_admin_bar_translate',
                                'post_id'     => $pld['source_id'],
                                'target_lang' => $code,
                                'redirect_to' => rawurlencode($pld['current_url']),
                            ], admin_url('admin-post.php')),
                            'aitrfoel_ab_trans_' . $pld['source_id']
                        );

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-retrans-' . $code,
                            'title'  => $flag_html . sprintf(/* translators: 1: language name, 2: language code */ esc_html__('Retranslate to %s (%s)', 'ai-translator-for-elementor-and-polylang'), esc_html($lang_name), strtoupper($code)),
                            'href'   => esc_url($retrans_url),
                        ]);

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-edit-' . $code,
                            'title'  => '<span class="aitrfoel-bar-sublink" style="margin-left:22px;">' . sprintf(/* translators: %s: language name */ esc_html__('Edit %s Translation', 'ai-translator-for-elementor-and-polylang'), strtoupper($code)) . '</span>',
                            'href'   => esc_url(get_edit_post_link($trans_post_id)),
                        ]);

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-view-' . $code,
                            'title'  => '<span class="aitrfoel-bar-sublink" style="margin-left:22px;">' . sprintf(/* translators: %s: language name */ esc_html__('View %s Version', 'ai-translator-for-elementor-and-polylang'), strtoupper($code)) . '</span>',
                            'href'   => esc_url(get_permalink($trans_post_id)),
                        ]);
                    } else {
                        $trans_url = wp_nonce_url(
                            add_query_arg([
                                'action'      => 'aitrfoel_admin_bar_translate',
                                'post_id'     => $pld['source_id'],
                                'target_lang' => $code,
                                'redirect_to' => rawurlencode($pld['current_url']),
                            ], admin_url('admin-post.php')),
                            'aitrfoel_ab_trans_' . $pld['source_id']
                        );

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-trans-' . $code,
                            'title'  => $flag_html . sprintf(/* translators: 1: language name, 2: language code */ esc_html__('Translate to %s (%s)', 'ai-translator-for-elementor-and-polylang'), esc_html($lang_name), strtoupper($code)),
                            'href'   => esc_url($trans_url),
                        ]);
                    }
                }
            } else {
                $retrans_current_url = wp_nonce_url(
                    add_query_arg([
                        'action'      => 'aitrfoel_admin_bar_translate',
                        'post_id'     => $pld['source_id'],
                        'target_lang' => $pld['post_lang'],
                        'redirect_to' => rawurlencode($pld['current_url']),
                    ], admin_url('admin-post.php')),
                    'aitrfoel_ab_trans_' . $pld['source_id']
                );

                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-retrans-current',
                    'title'  => sprintf(/* translators: %s: default language */ esc_html__('Retranslate this page (from %s)', 'ai-translator-for-elementor-and-polylang'), strtoupper($pld['default_lang'])),
                    'href'   => esc_url($retrans_current_url),
                ]);

                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-edit-source',
                    'title'  => '<span class="aitrfoel-bar-sublink">' . sprintf(/* translators: %s: default language */ esc_html__('Edit Original (%s)', 'ai-translator-for-elementor-and-polylang'), strtoupper($pld['default_lang'])) . '</span>',
                    'href'   => esc_url(get_edit_post_link($pld['source_id'])),
                ]);

                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-view-source',
                    'title'  => '<span class="aitrfoel-bar-sublink">' . sprintf(/* translators: %s: default language */ esc_html__('View Original (%s)', 'ai-translator-for-elementor-and-polylang'), strtoupper($pld['default_lang'])) . '</span>',
                    'href'   => esc_url(get_permalink($pld['source_id'])),
                ]);

                foreach ($pld['available_langs'] as $code => $lang) {
                    if ($code === $pld['post_lang'] || $code === $pld['default_lang']) {
                        continue;
                    }

                    $lang_name = is_object($lang)
                        ? ($lang->name ?? strtoupper($code))
                        : (is_array($lang) ? ($lang['name'] ?? strtoupper($code)) : strtoupper($code));

                    $has_translation = isset($pld['translations'][$code]) && $pld['translations'][$code];
                    $trans_post_id   = $has_translation ? absint($pld['translations'][$code]) : 0;
                    $flag_html       = class_exists('AITRFOEL_Language_Mapper') ? AITRFOEL_Language_Mapper::get_flag_html($code, 16, 'margin-right:6px;vertical-align:middle;') : '';

                    if ($has_translation && $trans_post_id) {
                        $other_retrans_url = wp_nonce_url(
                            add_query_arg([
                                'action'      => 'aitrfoel_admin_bar_translate',
                                'post_id'     => $pld['source_id'],
                                'target_lang' => $code,
                                'redirect_to' => rawurlencode($pld['current_url']),
                            ], admin_url('admin-post.php')),
                            'aitrfoel_ab_trans_' . $pld['source_id']
                        );

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-retrans-' . $code,
                            'title'  => $flag_html . sprintf(/* translators: 1: language name, 2: language code */ esc_html__('Retranslate to %s (%s)', 'ai-translator-for-elementor-and-polylang'), esc_html($lang_name), strtoupper($code)),
                            'href'   => esc_url($other_retrans_url),
                        ]);

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-view-' . $code,
                            'title'  => '<span class="aitrfoel-bar-sublink" style="margin-left:22px;">' . sprintf(/* translators: %s: language name */ esc_html__('View %s Version', 'ai-translator-for-elementor-and-polylang'), strtoupper($code)) . '</span>',
                            'href'   => esc_url(get_permalink($trans_post_id)),
                        ]);
                    } else {
                        $other_trans_url = wp_nonce_url(
                            add_query_arg([
                                'action'      => 'aitrfoel_admin_bar_translate',
                                'post_id'     => $pld['source_id'],
                                'target_lang' => $code,
                                'redirect_to' => rawurlencode($pld['current_url']),
                            ], admin_url('admin-post.php')),
                            'aitrfoel_ab_trans_' . $pld['source_id']
                        );

                        $wp_admin_bar->add_node([
                            'parent' => 'aitrfoel-admin-bar-hub',
                            'id'     => 'aitrfoel-bar-trans-' . $code,
                            'title'  => $flag_html . sprintf(/* translators: 1: language name, 2: language code */ esc_html__('Translate to %s (%s)', 'ai-translator-for-elementor-and-polylang'), esc_html($lang_name), strtoupper($code)),
                            'href'   => esc_url($other_trans_url),
                        ]);
                    }
                }
            }

            // Divider between single page actions and global hub items
            $wp_admin_bar->add_node([
                'parent' => 'aitrfoel-admin-bar-hub',
                'id'     => 'aitrfoel-bar-divider',
                'title'  => '<div style="height:1px;background:#334155;margin:4px 0;"></div>',
                'href'   => false,
            ]);
        }

        // 2. Submenu: Word Balance & Quota
        $bar_color = $is_unlimited ? '#10b981' : (($pct >= 100) ? '#ef4444' : (($pct >= 80) ? '#f59e0b' : '#10b981'));
        $usage_title = sprintf(
            '<div class="aitrfoel-bar-stat-box">
                <div class="aitrfoel-bar-stat-label"><span>%s: %s</span> <span style="font-weight:700;color:%s;">%s</span></div>
                <div class="aitrfoel-bar-progress-track"><div class="aitrfoel-bar-progress-fill" style="width:%d%%;background:%s;"></div></div>
            </div>',
            esc_html__('Plan', 'ai-translator-for-elementor-and-polylang'),
            esc_html($plan_name),
            esc_attr($bar_color),
            $is_unlimited ? esc_html__('Unlimited', 'ai-translator-for-elementor-and-polylang') : esc_html(sprintf(/* translators: 1: words used, 2: monthly word limit, 3: percentage used */ __('%s / %s words (%d%%)', 'ai-translator-for-elementor-and-polylang'), number_format($used), number_format($limit), $pct)),
            $is_unlimited ? 100 : $pct,
            esc_attr($bar_color)
        );

        $wp_admin_bar->add_node([
            'parent' => 'aitrfoel-admin-bar-hub',
            'id'     => 'aitrfoel-bar-usage',
            'title'  => $usage_title,
            'href'   => $dashboard_url,
        ]);

        // 3. Submenu: Action / Upgrade / Giveaway (ONLY for free / unlicensed accounts)
        if (!$is_pro && !$is_unlimited) {
            if ($is_limit_reached) {
                // Free user limit reached CTA
                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-upgrade-promo',
                    'title'  => '<span style="color:#f59e0b;font-weight:700;">' . esc_html__('Upgrade to PRO (20% OFF: MEEP20)', 'ai-translator-for-elementor-and-polylang') . '</span>',
                    'href'   => $pricing_url,
                    'meta'   => ['target' => '_blank'],
                ]);

                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-bonus-words',
                    'title'  => '<span style="color:#38bdf8;font-weight:600;">' . esc_html__('Claim +2,500 Bonus Words (Share)', 'ai-translator-for-elementor-and-polylang') . '</span>',
                    'href'   => $dashboard_url . '#bonus-words',
                ]);
            } else {
                $wp_admin_bar->add_node([
                    'parent' => 'aitrfoel-admin-bar-hub',
                    'id'     => 'aitrfoel-bar-upgrade-pro',
                    'title'  => '<span style="color:#38bdf8;font-weight:600;">' . esc_html__('Upgrade to PRO (Claude & DeepL)', 'ai-translator-for-elementor-and-polylang') . '</span>',
                    'href'   => $pricing_url,
                    'meta'   => ['target' => '_blank'],
                ]);
            }
        }

        // 4. Submenu: Site Translation Score
        if ($total_pages > 0) {
            $score_text = sprintf(
                /* translators: 1: percentage translated, 2: translated pages, 3: total pages */ __('Site Progress: %d%% (%d/%d translated)', 'ai-translator-for-elementor-and-polylang'),
                $score,
                $translated_pages,
                $total_pages
            );

            $wp_admin_bar->add_node([
                'parent' => 'aitrfoel-admin-bar-hub',
                'id'     => 'aitrfoel-bar-health',
                'title'  => '<span style="color:#94a3b8;">' . esc_html($score_text) . '</span>',
                'href'   => $translate_all_url,
            ]);
        }

        // 5. Submenu: Quick Navigation
        $wp_admin_bar->add_node([
            'parent' => 'aitrfoel-admin-bar-hub',
            'id'     => 'aitrfoel-bar-dashboard-link',
            'title'  => esc_html__('Translation Dashboard', 'ai-translator-for-elementor-and-polylang'),
            'href'   => $dashboard_url,
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'aitrfoel-admin-bar-hub',
            'id'     => 'aitrfoel-bar-queue-link',
            'title'  => esc_html__('Translate All Queue', 'ai-translator-for-elementor-and-polylang'),
            'href'   => $translate_all_url,
        ]);

        $wp_admin_bar->add_node([
            'parent' => 'aitrfoel-admin-bar-hub',
            'id'     => 'aitrfoel-bar-settings-link',
            'title'  => esc_html__('Settings Hub', 'ai-translator-for-elementor-and-polylang'),
            'href'   => $settings_url,
        ]);
    }

    /**
     * Custom CSS for Admin Bar styling
     */
    public function render_admin_bar_css() {
        if (!is_admin_bar_showing()) {
            return;
        }
        ?>
        <style id="aitrfoel-admin-bar-hub-css">
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-item {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu .ab-item {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-bar-divider {
            pointer-events: none !important;
            min-height: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: 4px 0 !important;
            background: #334155 !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-bar-divider > .ab-item {
            display: none !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-icon {
            margin-right: 0 !important;
            display: inline-flex !important;
            align-items: center !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-icon svg {
            fill: currentColor !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub img,
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub a:hover img,
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu img,
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu a:hover img {
            width: 16px !important;
            height: 12px !important;
            min-width: 16px !important;
            max-width: 16px !important;
            min-height: 12px !important;
            max-height: 12px !important;
            object-fit: cover !important;
            transform: none !important;
            transition: none !important;
            display: inline-block !important;
            vertical-align: middle !important;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu .aitrfoel-bar-sublink {
            color: #94a3b8 !important;
            transition: color 0.15s ease;
        }
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu li:hover .aitrfoel-bar-sublink,
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu a:hover .aitrfoel-bar-sublink,
        #wpadminbar #wp-admin-bar-aitrfoel-admin-bar-hub .ab-submenu a:focus .aitrfoel-bar-sublink {
            color: #72aee6 !important;
        }
        .aitrfoel-bar-pill {
            display: inline-block;
            padding: 1px 7px;
            font-size: 10px;
            font-weight: 700;
            border-radius: 999px;
            line-height: 1.4;
            margin-left: 4px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .aitrfoel-pill-danger {
            background: #ef4444;
            color: #ffffff;
        }
        .aitrfoel-pill-warning {
            background: #f59e0b;
            color: #ffffff;
        }
        .aitrfoel-bar-stat-box {
            padding: 6px 0;
            min-width: 220px;
        }
        .aitrfoel-bar-stat-label {
            display: flex;
            justify-content: space-between;
            font-size: 11.5px;
            margin-bottom: 5px;
            color: #e2e8f0;
        }
        .aitrfoel-bar-progress-track {
            height: 5px;
            background: #334155;
            border-radius: 999px;
            overflow: hidden;
            width: 100%;
        }
        .aitrfoel-bar-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.3s ease;
        }
        </style>
        <?php
    }

    /**
     * Render dismissible admin notice when limit is reached
     */
    public function render_limit_reached_admin_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show on relevant admin pages (not spamming every single subpage)
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        $allowed_screens = ['dashboard', 'plugins', 'edit-page', 'edit-post', 'toplevel_page_ai-translator-for-elementor-and-polylang'];
        if (!in_array($screen->id, $allowed_screens, true) && strpos($screen->id, 'ai-translator') === false) {
            return;
        }

        // Check dismiss flag
        if (get_transient('aitrfoel_limit_notice_dismissed_' . get_current_user_id())) {
            return;
        }

        $license_manager = class_exists('AITRFOEL_License_Manager_Server')
            ? AITRFOEL_License_Manager_Server::get_instance()
            : null;

        $license_status = $license_manager ? $license_manager->get_license_status() : [];
        $used           = isset($license_status['words_used']) ? (int) $license_status['words_used'] : (int) ($license_status['words_translated'] ?? 0);
        $raw_limit      = isset($license_status['word_limit']) ? (int) $license_status['word_limit'] : 7200;
        $plan_name      = !empty($license_status['plan_name']) ? $license_status['plan_name'] : (!empty($license_status['plan']) ? ucfirst($license_status['plan']) : 'Free');
        $is_unlimited   = ($raw_limit === -1 || !empty($license_status['unlimited']) || !empty($license_status['is_unlimited']) || strtolower($plan_name) === 'agency lifetime');
        $limit          = $is_unlimited ? -1 : ($raw_limit <= 0 ? 7200 : $raw_limit);
        
        if ($is_unlimited || $limit <= 0 || $used < $limit) {
            return;
        }

        $pricing_url = aitrfoel_free_upgrade_url('admin-bar');
        $dashboard_url = admin_url('admin.php?page=ai-translator-for-elementor-and-polylang');
        ?>
        <div class="notice notice-warning is-dismissible aitrfoel-limit-admin-notice" style="border-left-color: #f59e0b; padding: 12px 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
                <div>
                    <strong style="font-size: 13.5px; color: #92400e; display: block; margin-bottom: 2px;">
                        <?php esc_html_e('Meep AI Translator: Free Word Limit Reached (7,200 Words Used)', 'ai-translator-for-elementor-and-polylang'); ?>
                    </strong>
                    <span style="font-size: 12.5px; color: #78350f;">
                        <?php esc_html_e('Your limit resets after the monthly cooldown, or upgrade now with coupon ', 'ai-translator-for-elementor-and-polylang'); ?>
                        <strong style="background: #fef3c7; padding: 1px 6px; border-radius: 4px; font-family: monospace;">MEEP20</strong>
                        <?php esc_html_e(' for 20% OFF unlimited translations.', 'ai-translator-for-elementor-and-polylang'); ?>
                    </span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="<?php echo esc_url($pricing_url); ?>" target="_blank" class="button button-primary" style="background: #0284c7; border-color: #0284c7;">
                        <?php esc_html_e('Upgrade to PRO (20% OFF)', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                    <a href="<?php echo esc_url($dashboard_url . '#bonus-words'); ?>" class="button button-secondary">
                        <?php esc_html_e('Claim +2,500 Bonus Words', 'ai-translator-for-elementor-and-polylang'); ?>
                    </a>
                </div>
            </div>
        </div>
        <script>
        jQuery(document).on('click', '.aitrfoel-limit-admin-notice .notice-dismiss', function() {
            jQuery.post(ajaxurl, {
                action: 'aitrfoel_dismiss_limit_notice',
                nonce: '<?php echo esc_js(wp_create_nonce('aitrfoel_ajax_nonce')); ?>'
            });
        });
        </script>
        <?php
    }
}
