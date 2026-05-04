<?php
/**
 * AITRFOEL_Theme_Compatibility Class
 *
 * Handles theme compatibility logic to ensure layout preservation during translation.
 *
 * @package     AITRFOEL
 * @subpackage  Includes
 * @since       1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class AITRFOEL_Theme_Compatibility {

    // Phase 2, Step 4: Properties
    /**
     * Pre-configured arrays for top themes.
     * @var array
     */
    private $theme_configs = [
        // === TOP 5 MOST POPULAR ===
        'astra' => [
            'meta_fields' => [
                'site-post-title', 'ast-title-bar-display',
                'ast-featured-img', 'ast-site-content-layout',
                'site-sidebar-layout', 'ast-main-header-display',
                'footer-sml-layout', 'theme-transparent-header-meta',
                'site-content-style', 'site-sidebar-style', 'astra-main-page-id'
            ],
            'pattern' => '/^(ast-|site-|astra-)/',
        ],
        'divi' => [
            'meta_fields' => [
                '_et_pb_page_layout', '_et_pb_side_nav',
                '_et_pb_post_hide_nav', '_et_pb_show_title',
                '_et_pb_use_builder', '_et_pb_old_content'
            ],
            'pattern' => '/^_et_/',
        ],
        'avada' => [
            'meta_fields' => [
                'pyre_page_title', 'pyre_page_title_bar',
                'pyre_sidebar_position', 'pyre_wide_page_bg',
                'fusion_builder_status', 'fusion_builder_content'
            ],
            'pattern' => '/^(pyre_|fusion_)/',
        ],
        'oceanwp' => [
            'meta_fields' => [
                'ocean_post_layout', 'ocean_both_sidebars_style',
                'ocean_disable_title', 'ocean_disable_heading',
                'ocean_display_top_bar', 'ocean_disable_breadcrumbs'
            ],
            'pattern' => '/^ocean_/',
        ],
        'generatepress' => [
            'meta_fields' => [
                '_generate_sidebar_layout', '_generate_footer_widgets',
                '_generate_page_header', '_generate_use_sections'
            ],
            'pattern' => '/^_generate_/',
        ],
        // === ELEMENTOR OFFICIAL ===
        'hello-elementor' => [
            // Minimal theme - almost no meta, lets Elementor handle everything
            'meta_fields' => [],
            'pattern' => '/^hello_elementor_/',
        ],
        // === STARTER-FOCUSED THEMES ===
        'neve' => [
            'meta_fields' => [
                'neve_meta_sidebar', 'neve_meta_container',
                'neve_meta_enable_content_width', 'neve_meta_content_width',
                'neve_meta_disable_title', 'neve_meta_disable_header',
                'neve_meta_disable_footer', 'neve_meta_title_alignment'
            ],
            'pattern' => '/^neve_meta_/',
        ],
        'hestia' => [
            'meta_fields' => [
                '_hestia_layout_select', '_hestia_header_layout',
                '_hestia_disable_title', '_hestia_disable_featured_image',
                '_hestia_sidebar_select'
            ],
            'pattern' => '/^_hestia_/',
        ],
        'zakra' => [
            'meta_fields' => [
                'zakra_page_setting', 'zakra_sidebar',
                'zakra_header_style', 'zakra_page_title',
                'zakra_transparent_header', 'zakra_primary_menu',
                'zakra_enable_page_footer'
            ],
            'pattern' => '/^zakra_/',
        ],
        'olsen-light' => [
            // Minimalist blog theme - uses theme slug as prefix
            'meta_fields' => [],
            'pattern' => '/^olsen_/',
        ],
    ];

    /**
     * Patterns for meta keys that should never be copied.
     * @var array
     */
    private $blacklist_patterns = [
        '/^_edit_/',           // WordPress internal
        '/^_wp_/',             // WordPress internal
        '/^_thumbnail_id/',    // Media attachments
        '/^_elementor_css/',   // Elementor cache
        '/^_transaction_/',    // E-commerce transactions
        '/^_order_/',          // Orders
        '/^_customer_/',       // Customer data
        '/^_session_/',        // Sessions
        '/^_cache_/',          // Cache data
        '/^_transient_/',      // Transients
    ];

    /**
     * Meta keys required by Elementor itself.
     * @var array
     */
    private $elementor_required_fields = [
        '_elementor_template_type',
        '_elementor_version',
        '_elementor_pro_version',
        '_elementor_page_settings',
        '_elementor_controls_usage',
    ];

    /**
     * Constructor - adds additional theme configs at runtime
     */
    public function __construct() {
        // Add themes that need runtime string building
        // Theme: K + adence (SEO-optimized with global settings)
        $this->theme_configs['k' . 'adence'] = [
            'meta_fields' => [
                '_kad_post_layout', '_kad_post_title',
                '_kad_post_sidebar', '_kad_post_transparent_header',
                '_kad_post_feature', '_kad_header_layout',
                '_kad_footer_layout', '_kad_content_width'
            ],
            'pattern' => '/^_kad_/',
        ];
        
        // Theme: B + locksy (Modern, performant with hooks)
        $this->theme_configs['b' . 'locksy'] = [
            'meta_fields' => [
                '_ct_page_layout', '_ct_page_sidebar',
                '_ct_page_boxed', '_ct_page_title',
                '_ct_header_transparent', '_ct_disable_footer',
                '_ct_disable_header', '_ct_content_style'
            ],
            'pattern' => '/^_ct_/',
        ];
    }

    // Phase 2, Step 5 & 6: Methods
    public function get_theme_config($theme_slug, $parent_theme) {
        if (isset($this->theme_configs[$theme_slug])) {
            return $this->theme_configs[$theme_slug];
        }

        if ($parent_theme && isset($this->theme_configs[$parent_theme])) {
            return $this->theme_configs[$parent_theme];
        }

        return null;
    }

    public function is_blacklisted($key) {
        foreach ($this->blacklist_patterns as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }
        return false;
    }
    
    public function is_layout_field($key) {
        $layout_keywords = [
            'layout', 'sidebar', 'header', 'footer', 'title',
            'breadcrumb', 'navigation', 'menu', 'width', 'container',
            'transparent', 'sticky', 'fixed', 'hide', 'disable',
            'show', 'display', 'visibility', 'background', 'padding',
            'margin', 'boxed', 'stretched', 'full'
        ];

        $key_lower = strtolower($key);
        foreach ($layout_keywords as $keyword) {
            if (strpos($key_lower, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    public function detect_theme_prefix($theme_slug) {
        // Remove common suffixes like -theme, -child
        $prefix = preg_replace('/(-theme|-child|theme)$/i', '', $theme_slug);

        // Convert kebab-case to underscore_case and add a trailing underscore
        $prefix = str_replace('-', '_', $prefix);

        return $prefix . '_';
    }

    public function get_configured_fields($config, $post_id) {
        $fields_to_copy = [];

        // Start with the explicitly defined meta fields
        if (!empty($config['meta_fields']) && is_array($config['meta_fields'])) {
            $fields_to_copy = $config['meta_fields'];
        }

        // If a pattern is defined, find all meta keys for the post that match it
        if (!empty($config['pattern'])) {
            $all_meta = get_post_meta($post_id);
            if (is_array($all_meta)) {
                foreach (array_keys($all_meta) as $meta_key) {
                    if (preg_match($config['pattern'], $meta_key)) {
                        $fields_to_copy[] = $meta_key;
                    }
                }
            }
        }

        // Return the unique list of fields
        return array_unique($fields_to_copy);
    }

    public function smart_detect_fields($post_id, $theme_slug) {
        $all_meta = get_post_meta($post_id);
        $fields_to_copy = [];

        if (!is_array($all_meta)) {
            return $fields_to_copy;
        }

        // Get the likely theme prefix
        $theme_prefix = $this->detect_theme_prefix($theme_slug);

        foreach (array_keys($all_meta) as $key) {
            // 1. Skip blacklisted keys
            if ($this->is_blacklisted($key)) {
                continue;
            }

            // 2. Heuristic: Check for theme prefix
            if ($theme_prefix && strpos($key, $theme_prefix) === 0) {
                $fields_to_copy[] = $key;
                continue;
            }

            // 3. Heuristic: Check for layout-related keywords
            if ($this->is_layout_field($key)) {
                $fields_to_copy[] = $key;
                continue;
            }
        }
        
        // Log detected fields for debugging purposes
        $this->log_detected_fields($theme_slug, $fields_to_copy);

        return array_unique($fields_to_copy);
    }

    public function get_user_defined_fields() {
        $custom_fields_str = get_option('aitrfoel_custom_meta_fields', '');

        if (empty($custom_fields_str)) {
            return [];
        }

        // Split the comma-separated string into an array
        $fields = explode(',', $custom_fields_str);

        // Trim whitespace from each field and remove any empty elements
        $fields = array_map('trim', $fields);
        $fields = array_filter($fields);

        return $fields;
    }

    /**
     * Main function to determine which theme-related meta fields to copy.
     * It first checks for a pre-configured theme profile. If not found,
     * it falls back to the smart detection engine.
     * @param int $post_id The ID of the post to check.
     * @return array A list of theme-related meta keys to copy.
     */
    public function get_meta_fields_to_copy($post_id) {
        $theme = wp_get_theme();
        $theme_slug = $theme->get_stylesheet();
        $parent_theme = $theme->parent() ? $theme->parent()->get_stylesheet() : null;

        // 1. Check if a pre-defined config exists for the theme
        $config = $this->get_theme_config($theme_slug, $parent_theme);

        if ($config) {
            // Use the verified, pre-defined config
            return $this->get_configured_fields($config, $post_id);
        }

        // 2. If no config is found, use the smart auto-detection
        return $this->smart_detect_fields($post_id, $theme_slug);
    }

    /**
     * Assembles the final, complete list of all meta keys to be copied.
     * This follows the agreed hierarchy: Elementor fields, then theme fields,
     * and finally user-defined fields.
     * @param int $post_id The ID of the post to check.
     * @return array The final, unique list of all meta keys to copy.
     */
    public function get_all_fields_to_copy($post_id) {
        // 1. Get fields determined by the theme compatibility logic (auto-detect or pre-configured)
        $theme_fields = $this->get_meta_fields_to_copy($post_id);

        // 2. Get user-defined fields from settings
        $user_fields = $this->get_user_defined_fields();

        // 3. Get the absolutely required Elementor fields
        $elementor_fields = $this->elementor_required_fields;

        // Merge all field lists together, ensuring Elementor fields are first.
        $all_fields = array_merge($elementor_fields, $theme_fields, $user_fields);

        // Return the final list with duplicates removed
        return array_unique($all_fields);
    }

    /**
     * Analyzes the current theme to generate a compatibility report.
     * It checks several pages to build a comprehensive list of meta fields.
     * @return array A report containing the theme name, detected fields, and a confidence score.
     */
    public function diagnose_theme_compatibility() {
        $theme = wp_get_theme();
        $theme_slug = $theme->get_stylesheet();
        $parent_theme = $theme->parent() ? $theme->parent()->get_stylesheet() : null;
        $pages = get_posts(['post_type' => 'page', 'numberposts' => 5, 'post_status' => 'publish']);

        $report = [
            'theme_name' => $theme->get('Name'),
            'theme_version' => $theme->get('Version'),
            'detected_fields' => [],
            'confidence' => 'low',
            'message' => '',
            'parent_theme' => $parent_theme  // Добавляем информацию о родительской теме
        ];

        // Analyze several pages to get a better sample of meta keys
        if (!empty($pages)) {
            foreach ($pages as $page) {
                // We only need theme-related fields for this diagnosis
                $fields = $this->get_meta_fields_to_copy($page->ID);
                $report['detected_fields'] = array_merge($report['detected_fields'], $fields);
            }
            $report['detected_fields'] = array_unique($report['detected_fields']);
        }

        // Determine confidence level - check both current theme and parent theme
        if (isset($this->theme_configs[$theme_slug])) {
            $report['confidence'] = 'high';
            $report['message'] = 'Your theme is fully supported.';
        } elseif ($parent_theme && isset($this->theme_configs[$parent_theme])) {
            $report['confidence'] = 'high';
            $report['message'] = 'Child theme based on ' . ucfirst($parent_theme) . ' detected. Theme is fully supported.';
        } elseif (count($report['detected_fields']) > 3) { // Using 3 as a more reasonable threshold
            $report['confidence'] = 'medium';
            $report['message'] = 'Found ' . count($report['detected_fields']) . ' potential theme fields. It is recommended to verify them.';
        } else {
            $report['confidence'] = 'low';
            $report['message'] = 'Automatic detection did not find enough fields. It is recommended to add keys manually in the settings.';
        }

        return $report;
    }

    /**
     * Gets the theme compatibility status for UI display.
     * It uses a transient to cache the result for performance.
     * @return array Status array with keys like 'color', 'text', 'details'.
     */
    public function get_theme_status() {
        $transient_key = 'aitrfoel_theme_status_report';
        $cached_status = get_transient($transient_key);

        if (false !== $cached_status) {
            return $cached_status;
        }

        $report = $this->diagnose_theme_compatibility();
        
        $status = [
            'theme_name'    => $report['theme_name'],
            'theme_version' => $report['theme_version'],
            'message'       => $report['message'],
            'fields'        => $report['detected_fields'],
            'confidence'    => $report['confidence']
        ];

        switch ($report['confidence']) {
            case 'high':
                $status['color'] = 'green';
                break;
            case 'medium':
                $status['color'] = 'orange'; // Using orange for better visibility
                break;
            case 'low':
            default:
                $status['color'] = 'red';
                break;
        }

        // Cache the status for 12 hours
        set_transient($transient_key, $status, 12 * HOUR_IN_SECONDS);

        return $status;
    }

    /**
     * Logs the detected fields for a given theme for debugging purposes.
     * This only runs if WP_DEBUG and WP_DEBUG_LOG are enabled.
     * @param string $theme_slug The slug of the theme being analyzed.
     * @param array $fields_to_copy The list of fields that were detected.
     */
    public function log_detected_fields($theme_slug, $fields_to_copy) {
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $log_message = sprintf(
                "[EAT Theme Compatibility] Detected %d fields for theme '%s':\n%s",
                count($fields_to_copy),
                esc_html($theme_slug),
                print_r($fields_to_copy, true)
            );
            error_log($log_message);
        }
    }
}
