<?php
/**
 * Built-in Language Management Integration
 * Provides native language management without requiring Polylang or WPML
 * Extends the Multilingual Interface for compatibility with the abstraction layer
 * 
 * Data storage:
 * - Languages stored in wp_options ('aitrfoel_builtin_languages', 'aitrfoel_builtin_default_language')
 * - Post-language association via custom taxonomy 'aitrfoel_language'
 * - Translation groups via custom DB table '{prefix}_aitrfoel_translation_groups'
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Builtin_Integration extends AITRFOEL_Multilingual_Interface {
    
    private static $instance = null;
    
    /**
     * Custom taxonomy name for post-language association
     */
    const TAXONOMY = 'aitrfoel_language';
    
    /**
     * DB table name (without prefix)
     */
    const TABLE_NAME = 'aitrfoel_translation_groups';
    
    /**
     * Option keys
     */
    const OPTION_LANGUAGES = 'aitrfoel_builtin_languages';
    const OPTION_DEFAULT_LANG = 'aitrfoel_builtin_default_language';
    const OPTION_ENABLED = 'aitrfoel_builtin_enabled';
    const OPTION_DB_VERSION = 'aitrfoel_builtin_db_version';
    const OPTION_URL_STRUCTURE = 'aitrfoel_builtin_url_structure'; // 'subdirectory', 'parameter'
    
    /**
     * Current DB schema version
     */
    const DB_VERSION = '1.0';
    
    /**
     * Cached languages array
     * @var array|null
     */
    private $cached_languages = null;
    
    /**
     * Current frontend language
     * @var string|null
     */
    private $current_language = null;

    /**
     * Per-request memo of get_post_translations() results, keyed by post ID.
     * @var array
     */
    private static $translations_memo = [];

    /**
     * Elementor template IDs currently being swap-rendered. Used as a re-entrancy /
     * cycle guard so swapping [elementor-template] shortcodes to their translation
     * can never recurse infinitely (which would exhaust memory and fatal Elementor's
     * renderer) when templates reference each other or themselves.
     * @var int[]
     */
    private static $elementor_shortcode_render_stack = [];

    /**
     * Whether we have an output buffer open around the theme header for link
     * localization (declared to avoid PHP 8.2+ dynamic-property deprecation).
     * @var bool
     */
    private $header_ob_active = false;

    /**
     * Resolved language for custom string translations (set once on `wp`).
     * @var string|null
     */
    private $st_lang = null;

    /**
     * Per-request cache of SureCart product translated fields, keyed "sc_id|lang".
     * @var array
     */
    private $sc_translated_fields_cache = [];

    /**
     * Get singleton instance
     * @return AITRFOEL_Builtin_Integration
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Register taxonomy (after CPTs like 'product' are registered)
        add_action('init', [$this, 'register_taxonomy'], 20);
        // Re-attach late so custom post types registered after priority 20
        // (JetEngine, SureCart, other CPT plugins) also become translatable.
        add_action('init', [$this, 'register_taxonomy'], 99);

        // If init already fired (e.g. during AJAX), register taxonomy/rewrite rules immediately
        if (did_action('init')) {
            $this->register_taxonomy();
        }
        
        // Register REST API endpoints for inline translations
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Ensure DB table exists
        $this->maybe_create_table();
        
        // Frontend URL rewriting and language detection (after CPTs registered)
        add_action('init', [$this, 'add_rewrite_rules'], 25);
        // Second pass after late CPT registration (JetEngine, SureCart and other CPT
        // plugins often register on init priority > 25). Re-registers the prefixed
        // rules so late CPTs get their single permalink rule, and auto-flushes once if
        // a CPT rule is missing — otherwise the catch-all page rule 404s the CPT URL.
        add_action('init', [$this, 'add_rewrite_rules'], 99);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('init', [$this, 'init_language_detection'], 5);
        add_action('parse_request', [$this, 'detect_language_from_request'], -100);

        // After a default-language switch, 301 the now-stale old URLs (only on 404,
        // and only when a redirect map was stored by the switch tool).
        if (self::is_frontend_or_ajax() && get_option('aitrfoel_lang_switch_redirects')) {
            add_action('template_redirect', [$this, 'maybe_redirect_after_lang_switch'], 1);
        }

        // Persist the resolved language in a cookie so unprefixed AJAX requests
        // (WooCommerce cart fragments / mini-cart) can recover the current language.
        if (self::is_frontend_or_ajax()) {
            add_action('template_redirect', [$this, 'maybe_set_language_cookie'], 1);
        }
        
        // Switch WordPress locale on frontend based on detected language
        if (self::is_frontend_or_ajax()) {
            add_filter('locale', [$this, 'filter_locale']);
            // Force-reload translations for the detected language as early as
            // possible, so WooCommerce/theme UI strings that may have been loaded
            // in the default language get switched (cart, checkout, mini-cart).
            add_action('init', [$this, 'maybe_switch_locale_early'], 99);
            // Force correct HTML lang attribute regardless of theme or SEO plugin overrides
            add_filter('language_attributes', [$this, 'filter_language_attributes'], 999);

            // Custom string translations — admin-defined overrides for UI strings
            // that aren't in any .mo file (WooCommerce gateway titles/descriptions,
            // checkout privacy text, third-party plugin strings). Registered on `wp`
            // so the current language is known and resolved only once.
            add_action('wp', [$this, 'register_string_translations']);
            
            // Swiper height fixes
            add_action('wp_footer', [$this, 'fix_jet_gallery_swiper_height'], 9999);

            // Scopes frontend queries to the current language
            add_action('pre_get_posts', [$this, 'filter_pre_get_posts'], 20);
        }

        // Permalink filtering for language prefixes
        add_filter('post_link', [$this, 'filter_permalink'], 10, 2);
        add_filter('page_link', [$this, 'filter_page_permalink'], 10, 2);
        add_filter('post_type_link', [$this, 'filter_permalink'], 10, 2);
        
        // Filter home_url to include language prefix (fixes logo link etc.)
        if (self::is_frontend_or_ajax()) {
            add_filter('home_url', [$this, 'filter_home_url'], 10, 4);
            // Make WooCommerce's AJAX endpoint (cart fragments / mini-cart) carry the
            // language prefix, so the fragment request is detected as the current
            // language from the URL itself instead of an unprefixed default.
            add_filter('woocommerce_ajax_get_endpoint', [$this, 'filter_wc_ajax_endpoint'], 10, 1);
        }

        // Frontend query filtering
        if (self::is_frontend_or_ajax()) {
            // Runs before WooCommerce's product_query (priority 10): converts a translated
            // Shop page that resolved as a singular page into a clean product archive, so the
            // product loop isn't pinned to the page's own ID (which yields zero products).
            add_action('pre_get_posts', [$this, 'maybe_convert_shop_page_query'], 5);
            add_action('pre_get_posts', [$this, 'filter_frontend_queries']);
            
            // Filter taxonomy term names to show translated versions
            add_filter('get_term', [$this, 'filter_term_name'], 10, 2);
            add_filter('get_terms', [$this, 'filter_terms_names'], 10, 4);
            
            // Filter WooCommerce and WordPress page IDs to translated versions
            add_filter('pre_option_page_on_front', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_shop_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_cart_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_checkout_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_myaccount_page_id', [$this, 'filter_translated_page_option'], 10, 2);
            add_filter('pre_option_woocommerce_terms_page_id', [$this, 'filter_translated_page_option'], 10, 2);

            // Localize WooCommerce / Elementor "Products" widget queries to the current language.
            // Manual selection (post__in/include) is remapped to the current-language translations;
            // category / generic queries are scoped to the current language via tax_query so shared
            // product categories don't leak default-language products into translated pages.
            if (class_exists('WooCommerce') && get_option('aitrfoel_woocommerce_support', true)) {
                add_filter('woocommerce_shortcode_products_query', [$this, 'filter_products_widget_query'], 20, 3);
                add_filter('woocommerce_shortcode_products_query_results', [$this, 'filter_products_widget_results'], 20, 2);

                // Display-time safety net: if a source-language product is rendered on a
                // translated page (single product, related/upsell/cross-sell, Elementor
                // single-product templates), show its translated short description.
                add_filter('woocommerce_short_description', [$this, 'filter_product_short_description'], 20, 1);

                // Mini-cart / cart: point each cart item's link and name to the
                // current-language translation, so the basket preview doesn't send
                // visitors to the source-language product page.
                add_filter('woocommerce_cart_item_permalink', [$this, 'filter_cart_item_permalink'], 20, 3);
                add_filter('woocommerce_cart_item_name', [$this, 'filter_cart_item_name'], 20, 3);

                // "View cart" / "Checkout" links (mini-cart buttons, etc.): resolve
                // directly to the current-language cart/checkout page. This is more
                // direct than the pre_option_* page-id swap and avoids option-cache
                // timing issues during AJAX fragment rendering.
                add_filter('woocommerce_get_cart_url', [$this, 'filter_wc_cart_url'], 20, 1);
                add_filter('woocommerce_get_checkout_url', [$this, 'filter_wc_checkout_url'], 20, 1);

                // Routing-independent loop localization: when any product loop (Elementor
                // Products / Loop Grid, related, up-sells, shortcodes, theme loops) renders
                // a source-language product on a translated page, swap the post/product
                // globals to the translation so title, link, price, short description and
                // add-to-cart all render in the current language. This covers widgets that
                // build their query without passing through woocommerce_shortcode_products_query.
                add_action('the_post', [$this, 'localize_loop_product'], 10, 1);

                // Scope Elementor Pro's native Products / Loop Grid widget queries to the
                // current language. These widgets build their own WP_Query via
                // elementor/query/query_args and bypass woocommerce_shortcode_products_query.
                add_filter('elementor/query/query_args', [$this, 'filter_elementor_query_lang'], 20, 2);
            }

            // Localize absolute internal links (e.g. /fr/...) inside post content and Elementor
            // widgets to the current-language translated target.
            if (get_option('aitrfoel_localize_internal_links', true)) {
                add_filter('the_content', [$this, 'localize_internal_links'], 99);
                add_filter('elementor/frontend/the_content', [$this, 'localize_internal_links'], 99);
                // Catch Elementor widgets whose links are rendered outside the_content
                // (Button, Icon, Image, Call to Action, etc.).
                add_filter('elementor/widget/render_content', [$this, 'localize_elementor_widget_links'], 99, 2);
            }

            // Scope the "Previous/Next post" navigation (get_adjacent_post, used by
            // themes like Shoptimizer for single blog posts) to the viewed post's
            // language, so an English post links to English neighbours instead of
            // leaking French/Spanish/German translations.
            add_filter('get_previous_post_where', [$this, 'filter_adjacent_post_where'], 10, 5);
            add_filter('get_next_post_where', [$this, 'filter_adjacent_post_where'], 10, 5);

            // Scope JetEngine Listing Grid / Query Builder queries to the current
            // language, so a listing of a translatable CPT shows the current-language
            // items (e.g. English "realisaties") instead of the source-language posts.
            add_filter('jet-engine/listing/grid/posts-query-args', [$this, 'filter_jetengine_query_lang'], 20, 1);
            add_filter('jet-engine/query-builder/posts/query-args', [$this, 'filter_jetengine_query_lang'], 20, 1);
            add_filter('jet-engine/query-builder/queries/query-args', [$this, 'filter_jetengine_query_lang'], 20, 1);

            // Astra Header Builder custom buttons: localize their link to the current
            // language's translation (these are theme-builder elements, not menu items
            // or content links, so they aren't covered elsewhere). Covers up to 4
            // buttons and both the array ("-link-option") and string ("-link") shapes.
            for ($aitrfoel_btn = 1; $aitrfoel_btn <= 4; $aitrfoel_btn++) {
                add_filter("theme_mod_header-button{$aitrfoel_btn}-link-option", [$this, 'localize_astra_button_link'], 20, 1);
                add_filter("theme_mod_header-button{$aitrfoel_btn}-link", [$this, 'localize_astra_button_link'], 20, 1);
                // Translate the button label text via String Translations (Tools →
                // String Translations): the label is an Astra theme_mod rendered as
                // static HTML, so it never reaches gettext on its own.
                add_filter("theme_mod_header-button{$aitrfoel_btn}-text", [$this, 'filter_string_value'], 20, 1);
            }

            // Robust, theme-level fallback: buffer the Astra header and localize every
            // internal link in it to the current-language translation. This catches
            // header-builder custom buttons, custom HTML, and any other theme-rendered
            // links that aren't menu items — without per-client workarounds. The
            // language switcher's intentional cross-language links are preserved (they
            // are wrapped in <!-- aitrfoel-no-localize --> markers).
            if (get_option('aitrfoel_localize_internal_links', true)) {
                add_action('astra_header_before', [$this, 'start_header_link_localization'], 0);
                add_action('astra_header_after', [$this, 'end_header_link_localization'], 99999);
            }
        }

        // SEO: hreflang tags
        add_action('wp_head', [$this, 'output_hreflang_tags']);
        
        // Sync page attributes (menu_order, post_date, post_date_gmt)
        add_action('save_post', [$this, 'sync_translation_attributes'], 10, 3);

        // Redirect unprefixed default-language URLs to prefixed when option is enabled
        add_action('template_redirect', [$this, 'maybe_redirect_default_lang_prefix']);
        
        // Prevent WordPress canonical redirect from stripping language prefix
        add_filter('redirect_canonical', [$this, 'filter_redirect_canonical'], 10, 2);
        
        // Preload language switcher CSS in <head> for all frontend pages
        add_action('wp_head', [$this, 'maybe_preload_switcher_css'], 99);
        
        // Language switcher shortcode
        add_action('init', [$this, 'register_shortcode']);
        
        // Language switcher widget
        add_action('widgets_init', [$this, 'register_widget']);
        
        // Nav menu language switcher items
        add_filter('wp_nav_menu_items', [$this, 'add_language_switcher_to_menu'], 20, 2);
        // Dropdown style: injected as real menu items so the theme renders its own
        // dropdown and mobile toggle (Astra, Hello, Kadence, Elementor nav widget...).
        add_filter('wp_nav_menu_objects', [$this, 'add_language_switcher_menu_objects'], 20, 2);
        
        // Per-language menu assignment: swap nav menu based on current language
        if (!is_admin()) {
            add_filter('wp_nav_menu_args', [$this, 'filter_nav_menu_by_language'], 10, 1);
            // Runtime: swap menu item URLs to translated posts
            add_filter('wp_nav_menu_objects', [$this, 'filter_menu_item_urls'], 10, 2);
            // Redirect untranslated pages to default language
            add_action('template_redirect', [$this, 'maybe_redirect_untranslated_page']);
        }
        
        // Per-language widget visibility
        if (!is_admin()) {
            add_filter('widget_display_callback', [$this, 'filter_widget_by_language'], 10, 3);
        }
        add_action('in_widget_form', [$this, 'widget_language_form'], 10, 3);
        add_filter('widget_update_callback', [$this, 'widget_language_save'], 10, 4);
        
        // Elementor Pro: swap header/footer/section templates to translated versions
        // Strategy (mirrors "Connect Polylang for Elementor" plugin):
        //   1. Translated templates have NO _elementor_conditions in DB.
        //   2. Elementor resolves candidates using only the original (default-lang) template's conditions.
        //   3. Once Elementor has picked the original template ID, we swap it to the translation via
        //      the elementor/theme/get_location_templates/template_id filter.
        //   4. The get_post_metadata filter ensures translated templates always return empty conditions
        //      even if conditions were accidentally written to DB (belt-and-suspenders).
        if (!is_admin()) {
            add_filter('elementor/theme/get_location_templates/template_id', [$this, 'swap_elementor_template_id_to_translation'], 10, 1);
            // Fallback: intercept Elementor data loading for any template
            add_filter('elementor/frontend/builder_content_data', [$this, 'filter_elementor_template_data'], 10, 2);
            // Swap the id attribute of the Elementor Pro [elementor-template id="X"]
            // shortcode to the current-language translation BEFORE Elementor renders
            // it. The builder_content_data filter above is a fallback for when this
            // entry point is bypassed; doing it at the shortcode boundary is the most
            // direct path and avoids any per-document caching keyed on the source id.
            add_filter('pre_do_shortcode_tag', [$this, 'swap_elementor_template_shortcode_id'], 10, 4);
            // Swap Gutenberg form block IDs (SureForms srfm/form, CF7, WPForms) to the current language's translation at render time
            add_filter('render_block_data', [$this, 'swap_form_block_id_at_runtime'], 10, 2);

            // JetThemeCore: swap the template id it is about to render to the current
            // language's translation. Confirmed in jet-theme-core 2.3.x:
            //   - location render (single/header/footer/archive, Elementor + block editor):
            //     jet-theme-core/location/render/template-id
            //   - page templates assigned by conditions:
            //     jet-theme-core/page-template-conditions/page-template-id
            add_filter('jet-theme-core/location/render/template-id', [$this, 'swap_jetthemecore_template_id'], 20, 1);
            add_filter('jet-theme-core/page-template-conditions/page-template-id', [$this, 'swap_jetthemecore_template_id'], 20, 1);
        }
        // Always (admin + frontend): make translated templates appear to have no conditions,
        // so they never compete with the original in Elementor's conditions resolver.
        add_filter('get_post_metadata', [$this, 'elementor_conditions_empty_on_translations'], 10, 3);

        // Front-end only: serve the translated Elementor data of a JetEngine listing
        // item (post type 'jet-engine') when it's rendered on a non-default language,
        // so the listing's static text (labels like "KLANT") shows in the right
        // language. Works for the front end and JetSmartFilters AJAX, since both read
        // the listing's _elementor_data.
        if (!is_admin() || wp_doing_ajax()) {
            add_filter('get_post_metadata', [$this, 'swap_jetengine_listing_data'], 10, 3);
        }

        // Front-end + AJAX: translate JetSmartFilters labels at render time. This is
        // the single point where every option-based filter (radio, checkboxes, select,
        // color-image, …) exposes its final args, including the prepended "Show all"
        // option, so it covers term option labels and the admin-entered "all" label in
        // both the initial render and AJAX re-renders. Term labels resolve from term
        // meta directly (so it works even when JetSmartFilters' indexer caches the
        // default-language names); admin strings flow through the custom-string map.
        if (!is_admin() || wp_doing_ajax()) {
            add_filter('jet-smart-filters/filter-instance/args', [$this, 'translate_jsf_filter_args'], 20, 2);
        }

        // Front-end: translate JetFormBuilder field blocks (labels, descriptions,
        // placeholders, option labels, button text) before they render. Uses WP
        // core's render_block_data filter, so it depends only on the stable
        // jet-forms/* block names and attribute keys, not JetFormBuilder internals.
        if (!is_admin()) {
            add_filter('render_block_data', [$this, 'translate_jfb_block_attrs'], 10, 1);
        }

        // Front-end + AJAX: translate the three most popular standalone form plugins
        // at render time (labels, descriptions, placeholders, option labels, button
        // text, confirmation messages), each through that plugin's own render hook.
        // Strings resolve through the custom-string map; the Auto-translate Strings
        // tool collects them from every form so there's no manual data entry.
        if (!is_admin() || wp_doing_ajax()) {
            // WPForms — full $form_data array (fields, choices, settings).
            add_filter('wpforms_frontend_form_data', [$this, 'translate_wpforms_form_data'], 20, 1);
            // Gravity Forms — $form array with GF_Field objects.
            add_filter('gform_pre_render', [$this, 'translate_gform_form'], 20, 1);
            // Contact Form 7 — rendered form HTML (text nodes) and result messages.
            add_filter('wpcf7_form_elements', [$this, 'translate_cf7_form_html'], 20, 1);
            add_filter('wpcf7_display_message', [$this, 'translate_cf7_display_message'], 20, 2);

            // SureCart — products are API-first and rendered server-side from the
            // product model. Its base Model exposes a getter filter, so translating
            // the `name` / `description` attributes once reaches every product block,
            // the collection grid, and checkout line items in a single place.
            if (get_option('aitrfoel_surecart_support', true)) {
                add_filter('surecart/product/get_attribute', [$this, 'translate_surecart_product_attribute'], 20, 3);
            }
        }

        // Merge any admin-configured "extra template post types" into both the
        // translation scope and the render-swap scope, so non-developers can support
        // additional builder template types (JetThemeCore parts, etc.) without code.
        add_filter('aitrfoel_template_post_types', [$this, 'merge_extra_template_post_types']);
        add_filter('aitrfoel_metadata_swap_template_types', [$this, 'merge_extra_template_post_types']);

        // Warn admins when Elementor "Element Caching" is active, as it can serve
        // cached (wrong-language) widget output across translated pages.
        add_action('admin_notices', [$this, 'maybe_warn_elementor_element_cache']);

        // REST API language fields
        add_action('rest_api_init', [$this, 'register_rest_fields']);
        
        // WordPress core sitemap: filter URLs to include only default-language posts
        add_filter('wp_sitemaps_posts_query_args', [$this, 'filter_sitemap_query'], 10, 2);
        
        // Admin bar: language switcher in toolbar
        add_action('admin_bar_menu', [$this, 'add_admin_bar_language_switcher'], 100);
        add_action('admin_head', [$this, 'admin_bar_switcher_css']);
        add_action('wp_head', [$this, 'admin_bar_switcher_css']);
        
        // Admin: language column in posts list
        if (is_admin()) {
            add_filter('manage_posts_columns', [$this, 'add_language_column']);
            add_filter('manage_pages_columns', [$this, 'add_language_column']);
            add_action('manage_posts_custom_column', [$this, 'render_language_column'], 10, 2);
            add_action('manage_pages_custom_column', [$this, 'render_language_column'], 10, 2);
            add_action('admin_head', [$this, 'language_column_css']);
            // Language filter dropdown and query filtering
            add_action('restrict_manage_posts', [$this, 'admin_language_filter_dropdown']);
            add_action('pre_get_posts', [$this, 'admin_filter_posts_by_language']);
            // Adjust status counts (All/Published/Draft/Trash) when language filter is active
            add_filter('views_edit-post', [$this, 'filter_post_status_counts_by_language']);
            add_filter('views_edit-page', [$this, 'filter_post_status_counts_by_language']);
        }
    }
    
    // =========================================================================
    // DATA LAYER: DB Table & Taxonomy
    // =========================================================================
    
    /**
     * Register the language taxonomy
     */
    public function register_taxonomy() {
        // Collect all public post types (excluding media attachments and internal non-translatable types)
        $post_types = array_keys(get_post_types(['public' => true], 'names'));
        $excluded_types = [
            'attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
            'sf_submission', 'sureforms_submission'
        ];
        $post_types = array_values(array_diff($post_types, $excluded_types));
        if (empty($post_types)) {
            $post_types = ['post', 'page'];
        }

        if (taxonomy_exists(self::TAXONOMY)) {
            // Taxonomy already registered — ensure it covers all public post types
            foreach ($post_types as $pt) {
                if (!is_object_in_taxonomy($pt, self::TAXONOMY)) {
                    register_taxonomy_for_object_type(self::TAXONOMY, $pt);
                }
            }
            return;
        }
        
        register_taxonomy(self::TAXONOMY, $post_types, [
            'label' => __('Language', 'ai-translator-for-elementor-and-polylang'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
        ]);
    }
    
    /**
     * Create the translation groups table if it doesn't exist
     */
    private function maybe_create_table() {
        $installed_version = get_option(self::OPTION_DB_VERSION, '0');
        
        if (version_compare($installed_version, self::DB_VERSION, '>=')) {
            return;
        }
        
        $this->create_table();
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }
    
    /**
     * Create the translation groups table
     */
    public function create_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT(20) UNSIGNED NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            language_code VARCHAR(10) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY post_language (post_id, language_code),
            KEY group_id (group_id),
            KEY post_id (post_id)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Get the full table name with prefix
     * @return string
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }
    
    /**
     * Get the next available group ID
     * @return int
     */
    private function get_next_group_id() {
        global $wpdb;
        $table = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
        $max = (int) $wpdb->get_var("SELECT MAX(group_id) FROM {$table}");

        // Atomic counter: MAX()+1 alone gave two pages translated at the same moment the same
        // group, linking unrelated pages together. LAST_INSERT_ID(expr) makes the increment atomic.
        $opt = 'aitrfoel_group_seq';
        if (false === get_option($opt, false)) {
            add_option($opt, (string) $max, '', 'no');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s",
                $opt
            ));
            wp_cache_delete($opt, 'options');
            if (!$updated) {
                break;
            }
            $next = (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
            if ($next > $max) {
                return $next;
            }
            // Counter fell behind rows written another way: jump it forward and try again.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s",
                (string) $max,
                $opt
            ));
        }
        return $max + 1; // fallback (previous behaviour)
    }
    
    /**
     * Get the group ID for a post
     * @param int $post_id
     * @return int|null
     */
    private function get_post_group_id($post_id) {
        global $wpdb;
        $table = $this->get_table_name();
        $group_id = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$table} WHERE post_id = %d LIMIT 1",
            $post_id
        ));
        return $group_id ? intval($group_id) : null;
    }
    
    // =========================================================================
    // LANGUAGE MANAGEMENT (Admin API)
    // =========================================================================
    
    /**
     * Add a language to the built-in system
     * 
     * @param string $code Language code (e.g., 'fr')
     * @param string $name English name (e.g., 'French')
     * @param string $native_name Native name (e.g., 'Français')
     * @param bool $is_default Whether this is the default language
     * @return bool
     */
    public function add_language($code, $name, $native_name = '', $is_default = false, $defer_language_pack = false) {
        $languages = $this->get_stored_languages();
        
        // Check if already exists
        foreach ($languages as $lang) {
            if ($lang['code'] === $code) {
                return false; // Already exists
            }
        }
        
        $languages[] = [
            'code' => sanitize_text_field($code),
            'name' => sanitize_text_field($name),
            'native_name' => sanitize_text_field($native_name ?: $name),
            'slug' => sanitize_text_field($code),
            'is_default' => $is_default,
        ];
        
        // If this is default, or if it's the first language, set as default
        if ($is_default || count($languages) === 1) {
            // Unset other defaults
            foreach ($languages as &$lang) {
                $lang['is_default'] = ($lang['code'] === $code);
            }
            update_option(self::OPTION_DEFAULT_LANG, $code);
        }
        
        update_option(self::OPTION_LANGUAGES, $languages);
        $this->cached_languages = null; // Clear cache
        
        // Ensure taxonomy is registered (may not be if called during AJAX)
        $this->register_taxonomy();
        
        // Ensure taxonomy term exists
        if (!term_exists($code, self::TAXONOMY)) {
            wp_insert_term($code, self::TAXONOMY, ['slug' => $code, 'description' => $name]);
        }

        // Best-effort: fetch the WordPress + plugin/theme (incl. WooCommerce) translation
        // packs for this language, so front-end UI strings — cart, checkout, my-account,
        // and other WooCommerce/core strings — render translated when filter_locale()
        // switches the locale on /<lang>/ pages. The default language is the source and
        // needs no pack. Failures never block adding the language.
        if (!$is_default && $defer_language_pack) {
            // Downloading packs means several wordpress.org API calls per language
            // (core pack + update checks + bulk upgrade) and made the setup wizard
            // hang for 60-75 s. Queue it; it runs in a background AJAX request.
            $this->queue_language_pack($code);
        } elseif (!$is_default) {
            try {
                $this->maybe_download_language_pack($code);
            } catch (\Throwable $e) {
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('Language pack download error for ' . $code . ': ' . $e->getMessage(), 'warning');
                }
            }
        }

        // Note: caller is responsible for calling flush_rewrite_rules() after batch operations

        return true;
    }

    /**
     * Best-effort install of translation packs for a language's WordPress locale.
     *
     * Downloads the core translation (which also registers the locale as "installed"),
     * then runs the language-pack upgrader to fetch plugin/theme translations (including
     * WooCommerce) for that locale. This makes WooCommerce's built-in strings render in
     * the target language via locale switching, without the admin manually installing the
     * WordPress site language. Entirely best-effort: every failure is swallowed/logged.
     *
     * @param string $code Short language code (e.g. 'ru')
     * @return void
     */
    private function maybe_download_language_pack($code, $run_upgrader = true) {
        // Needs admin-side includes, filesystem and network access.
        if (!is_admin()) {
            return;
        }

        $locale = $this->get_wp_locale($code);
        if (!$locale || $locale === 'en_US') {
            return; // English / unknown locale needs no pack
        }

        // Already installed — nothing to do.
        if (function_exists('get_available_languages') && in_array($locale, get_available_languages(), true)) {
            return;
        }

        if (!function_exists('wp_download_language_pack')) {
            $tr = ABSPATH . 'wp-admin/includes/translation-install.php';
            if (file_exists($tr)) {
                require_once $tr;
            }
        }
        if (!function_exists('wp_download_language_pack')) {
            return; // environment cannot install translations
        }

        // 1) Core translation for the locale (also marks it as installed/available).
        $core = wp_download_language_pack($locale);
        if (is_wp_error($core) || $core === false) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(sprintf('Language pack: core translation for %s unavailable (continuing).', $locale), 'warning');
            }
        } elseif (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(sprintf('Language pack: installed core translations for %s.', $locale), 'info');
        }

        if (!$run_upgrader) {
            return;
        }
        $this->run_language_pack_upgrader($locale);
    }

    /**
     * Fetch plugin/theme translation packs (incl. WooCommerce) for installed locales.
     * Runs the wordpress.org update checks once, so call it once per batch of languages.
     *
     * @param string $locale Locale used for logging only.
     */
    private function run_language_pack_upgrader($locale = '') {
        // Plugin + theme translations (incl. WooCommerce) for the now-installed locales.
        if (!class_exists('Language_Pack_Upgrader')) {
            $up = ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            if (file_exists($up)) {
                require_once $up;
            }
        }
        if (!class_exists('Language_Pack_Upgrader')) {
            return;
        }

        // Refresh update transients so available translation packs for the new locale
        // are discoverable by wp_get_translation_updates().
        if (function_exists('wp_clean_update_cache')) {
            wp_clean_update_cache();
        }
        if (function_exists('wp_update_plugins')) { wp_update_plugins(); }
        if (function_exists('wp_update_themes'))  { wp_update_themes(); }
        if (function_exists('wp_version_check'))  { wp_version_check(); }

        $skin     = class_exists('Automatic_Upgrader_Skin') ? new Automatic_Upgrader_Skin() : null;
        $upgrader = $skin ? new Language_Pack_Upgrader($skin) : new Language_Pack_Upgrader();
        // Empty first arg → upgrader pulls all pending translation updates for installed locales.
        $upgrader->bulk_upgrade([], ['clear_update_cache' => false]);

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(sprintf('Language pack: ran plugin/theme translation install for %s.', $locale), 'info');
        }
    }
    
    /**
     * Queue a language whose WordPress/plugin translation packs should be installed later.
     */
    public function queue_language_pack($code) {
        $pending = (array) get_option('aitrfoel_pending_language_packs', []);
        if (!in_array($code, $pending, true)) {
            $pending[] = $code;
            update_option('aitrfoel_pending_language_packs', $pending, false);
        }
    }

    /**
     * Install translation packs for all queued languages in one batch.
     * Core packs per locale, then a single plugin/theme pack upgrade run.
     *
     * @return int Number of languages processed.
     */
    public function process_pending_language_packs() {
        $pending = (array) get_option('aitrfoel_pending_language_packs', []);
        if (empty($pending)) {
            return 0;
        }
        // Clear first so a slow or failed run is never retried in a loop.
        delete_option('aitrfoel_pending_language_packs');
        $last_locale = '';
        foreach ($pending as $code) {
            try {
                $this->maybe_download_language_pack(sanitize_key($code), false);
                $last_locale = $this->get_wp_locale(sanitize_key($code)) ?: $last_locale;
            } catch (\Throwable $e) {
                if (class_exists('AITRFOEL_Logger')) {
                    AITRFOEL_Logger::log('Language pack download error for ' . $code . ': ' . $e->getMessage(), 'warning');
                }
            }
        }
        try {
            $this->run_language_pack_upgrader($last_locale);
        } catch (\Throwable $e) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log('Language pack upgrade error: ' . $e->getMessage(), 'warning');
            }
        }
        return count($pending);
    }

    /**
     * Remove a language from the built-in system
     * 
     * @param string $code Language code
     * @return bool
     */
    public function remove_language($code) {
        $languages = $this->get_stored_languages();
        $default = $this->get_default_language();
        
        // Don't allow removing the default language if it's the only one
        if ($code === $default && count($languages) <= 1) {
            return false;
        }
        
        $languages = array_values(array_filter($languages, function($lang) use ($code) {
            return $lang['code'] !== $code;
        }));
        
        update_option(self::OPTION_LANGUAGES, $languages);
        $this->cached_languages = null;
        
        // If we removed the default, set a new default
        if ($code === $default && !empty($languages)) {
            $languages[0]['is_default'] = true;
            update_option(self::OPTION_LANGUAGES, $languages);
            update_option(self::OPTION_DEFAULT_LANG, $languages[0]['code']);
        }
        
        flush_rewrite_rules();
        
        return true;
    }
    
    /**
     * Set the default language
     * 
     * @param string $code Language code
     * @return bool
     */
    /**
     * Switch the site's default (unprefixed) language by moving slug ownership.
     *
     * In the built-in model, the default language's posts hold the clean slug at the
     * root and other languages are served from suffixed slugs under a prefix. To make
     * a different language the default, this re-homes slug ownership per translation
     * group — the new default's post takes the clean base slug, the old default's post
     * takes a `{base}-{oldlang}` slug — then flips the default flag. It captures old
     * permalinks first and new permalinks after, storing a redirect map so old
     * non-default URLs 301 to their new location.
     *
     * Works entirely within the existing (tested) routing; no resolver changes.
     *
     * @param string $new_default Target language code.
     * @param bool   $dry_run     When true, only report what would change.
     * @return array|WP_Error
     */
    public function switch_default_language($new_default, $dry_run = true) {
        global $wpdb;

        $old_default = $this->get_default_language();
        if (!$this->language_exists($new_default)) {
            return new WP_Error('aitrfoel_bad_lang', __('Target language not found.', 'ai-translator-for-elementor-and-polylang'));
        }
        if ($new_default === $old_default) {
            return new WP_Error('aitrfoel_same_lang', __('That language is already the default.', 'ai-translator-for-elementor-and-polylang'));
        }

        $table = $this->get_table_name();
        $rows  = $wpdb->get_results("SELECT group_id, post_id, language_code FROM {$table}"); // phpcs:ignore
        $groups = [];

        // Pre-flight integrity check. The migration trusts the translation-group table
        // to know each post's language. If that table disagrees with the post's actual
        // language term, the migration would move the WRONG content to the root. Detect
        // any such mismatch and refuse to run until it's reconciled — this is the guard
        // that prevents the "everything shows the wrong language" failure.
        $mismatches = [];
        foreach ((array) $rows as $r) {
            $pid       = (int) $r->post_id;
            $table_lng = (string) $r->language_code;
            $groups[(int) $r->group_id][$table_lng] = $pid;

            $term_lng = $this->get_post_language($pid);
            // Only compare when the post has an explicit term and it differs.
            if ($term_lng && $term_lng !== $table_lng) {
                $mismatches[] = $pid;
            }
        }

        if (!empty($mismatches)) {
            $msg = sprintf(
                /* translators: %d: number of posts whose stored language is inconsistent. */
                __('Cannot switch the default language: %d item(s) have an inconsistent language assignment (the translation-group record disagrees with the post\'s language tag). Run Tools → Repair Translation Links first, then try again. Nothing was changed.', 'ai-translator-for-elementor-and-polylang'),
                count($mismatches)
            );
            return new WP_Error('aitrfoel_lang_mismatch', $msg, ['mismatches' => count($mismatches)]);
        }

        $plan = []; // each: new_post, new_from, new_to, new_rename, old_post, old_from, demoted
        foreach ($groups as $members) {
            $new_post = isset($members[$new_default]) ? (int) $members[$new_default] : 0;
            if (!$new_post) {
                continue;
            }
            $old_post = isset($members[$old_default]) ? (int) $members[$old_default] : 0;
            if ($old_post === $new_post) {
                continue; // same post both members — never act
            }
            $np = get_post($new_post);
            if (!$np) {
                continue;
            }

            // The new default takes the clean base slug: its own slug with a trailing
            // -{newlang} suffix removed ("about-us-id" → "about-us"). If the slug is
            // already clean and distinct (translated slugs like "tentang-kami"), it stays.
            $base   = $np->post_name;
            $suffix = '-' . $new_default;
            if (strlen($suffix) > 1 && substr($base, -strlen($suffix)) === $suffix) {
                $base = substr($base, 0, -strlen($suffix));
            }
            $new_rename = ($np->post_name !== $base);

            // The old default is demoted ONLY when it currently holds the exact slug the
            // new default will take — a genuine collision. Distinct slugs are left as-is.
            $old_slug  = $old_post ? (string) get_post_field('post_name', $old_post) : '';
            $collision = ($old_post && $old_slug !== '' && $old_slug === $base);
            $demoted   = $collision ? ($old_slug . '-' . $old_default) : '';

            // Nothing to rename — the default flip alone routes this group correctly.
            if (!$new_rename && !$collision) {
                continue;
            }

            $plan[] = [
                'new_post'   => $new_post,
                'new_from'   => $np->post_name,
                'new_to'     => $base,
                'new_rename' => $new_rename,
                'old_post'   => $collision ? $old_post : 0,
                'old_from'   => $collision ? $old_slug : '',
                'demoted'    => $demoted,
            ];
        }

        // Every group member's URL still changes when the default flips (the language
        // prefix moves) even without a slug rename — collect them all for redirects.
        $affected = [];
        foreach ($groups as $members) {
            if (isset($members[$new_default])) { $affected[(int) $members[$new_default]] = true; }
            if (isset($members[$old_default])) { $affected[(int) $members[$old_default]] = true; }
        }
        $affected = array_keys($affected);

        if ($dry_run) {
            $sample = [];
            foreach (array_slice($plan, 0, 25) as $p) {
                $sample[] = [
                    'promote' => $p['new_rename'] ? ($p['new_from'] . ' → ' . $p['new_to']) : ($p['new_from'] . ' (slug unchanged)'),
                    'demote'  => $p['old_post'] ? ($p['old_from'] . ' → ' . $p['demoted']) : '—',
                ];
            }
            return [
                'dry_run'     => true,
                'old_default' => $old_default,
                'new_default' => $new_default,
                'group_count' => count($plan),     // groups needing a slug change
                'affected'    => count($affected), // total items whose URL moves
                'sample'      => $sample,
            ];
        }

        // ── Execute ──────────────────────────────────────────────────────
        // 1) Capture OLD permalinks for every affected post (URLs change by slug rename
        //    and/or by the language prefix moving when the default flips).
        $old_urls = [];
        foreach ($affected as $pid) {
            $old_urls[$pid] = get_permalink($pid);
        }

        // 2) Apply only the necessary slug changes. Free a colliding slug (demote old)
        //    before the new default claims it.
        foreach ($plan as $p) {
            if ($p['old_post']) {
                wp_update_post(['ID' => $p['old_post'], 'post_name' => $p['demoted']]);
            }
            if ($p['new_rename']) {
                wp_update_post(['ID' => $p['new_post'], 'post_name' => $p['new_to']]);
            }
        }

        // 3) Flip the default flag and rebuild rewrite rules.
        $this->set_default_language($new_default);
        $this->cached_languages = null;
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }

        // 4) Build a redirect map (old path → new path) so old URLs don't 404.
        $home_path = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        $relpath = function ($url) use ($home_path) {
            $path = (string) wp_parse_url($url, PHP_URL_PATH);
            if ($home_path !== '' && strpos($path, $home_path) === 0) {
                $path = substr($path, strlen($home_path));
            }
            return '/' . trim($path, '/') . '/';
        };
        $map = [];
        foreach ($affected as $pid) {
            if (empty($old_urls[$pid])) {
                continue;
            }
            $old = $relpath($old_urls[$pid]);
            $new = $relpath(get_permalink($pid));
            if ($old !== $new && $old !== '//') {
                $map[$old] = $new;
            }
        }
        update_option('aitrfoel_lang_switch_redirects', $map, false);

        return [
            'dry_run'     => false,
            'old_default' => $old_default,
            'new_default' => $new_default,
            'group_count' => count($plan),
            'affected'    => count($affected),
            'redirects'   => count($map),
        ];
    }

    /**
     * After a default-language switch, 301 old URLs that would now 404 (the old
     * suffixed slugs) to their new location. Only runs on a 404 and only when a
     * redirect map exists, so there's no cost on normal requests.
     */
    public function maybe_redirect_after_lang_switch() {
        if (is_admin() || !$this->is_active() || !is_404()) {
            return;
        }
        $map = get_option('aitrfoel_lang_switch_redirects', []);
        if (empty($map) || !is_array($map)) {
            return;
        }
        $req  = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) wp_parse_url($req, PHP_URL_PATH);
        $home_path = rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/');
        if ($home_path !== '' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }
        $path = '/' . trim($path, '/') . '/';
        if (isset($map[$path])) {
            wp_safe_redirect(home_url($map[$path]), 301);
            exit;
        }
    }

    public function set_default_language($code) {
        $languages = $this->get_stored_languages();
        $found = false;
        
        foreach ($languages as &$lang) {
            $lang['is_default'] = ($lang['code'] === $code);
            if ($lang['code'] === $code) {
                $found = true;
            }
        }
        
        if (!$found) {
            return false;
        }
        
        update_option(self::OPTION_LANGUAGES, $languages);
        update_option(self::OPTION_DEFAULT_LANG, $code);
        $this->cached_languages = null;
        
        return true;
    }
    
    /**
     * Get languages from wp_options (raw stored format)
     * @return array
     */
    private function get_stored_languages() {
        if ($this->cached_languages !== null) {
            return $this->cached_languages;
        }
        $this->cached_languages = get_option(self::OPTION_LANGUAGES, []);
        return $this->cached_languages;
    }
    
    /**
     * Enable the built-in language system
     */
    public function enable() {
        update_option(self::OPTION_ENABLED, true);
        $this->maybe_create_table();
    }
    
    /**
     * Disable the built-in language system
     */
    public function disable() {
        update_option(self::OPTION_ENABLED, false);
    }
    
    /**
     * Check if built-in system is explicitly enabled
     * @return bool
     */
    public static function is_enabled() {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }
    
    // =========================================================================
    // MULTILINGUAL INTERFACE IMPLEMENTATION
    // =========================================================================
    
    /**
     * @inheritDoc
     */
    public function get_plugin_name() {
        return 'Meep Translator Built-in';
    }
    
    /**
     * Check if the current request is a frontend page load or a frontend-facing AJAX/REST request.
     *
     * @return bool
     */
    public static function is_frontend_or_ajax() {
        return !is_admin() 
            || (defined('DOING_AJAX') && DOING_AJAX) 
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('REST_REQUEST') && REST_REQUEST) 
            || isset($_GET['wc-ajax']);
    }

    /**
     * @inheritDoc
     */
    public function is_active() {
        return self::is_enabled() && !empty($this->get_stored_languages());
    }
    
    /**
     * @inheritDoc
     */
    public function get_available_languages() {
        $stored = $this->get_stored_languages();
        $languages = [];
        
        foreach ($stored as $lang_data) {
            $obj = new stdClass();
            $obj->slug = $lang_data['code'];
            $obj->code = $lang_data['code'];
            $obj->name = $lang_data['name'];
            $obj->native_name = $lang_data['native_name'] ?? $lang_data['name'];
            $obj->is_default = !empty($lang_data['is_default']);
            $obj->flag = ''; // Flag URL can be added later
            $languages[$lang_data['code']] = $obj;
        }
        
        return $languages;
    }
    
    /**
     * @inheritDoc
     */
    public function get_default_language() {
        $default = get_option(self::OPTION_DEFAULT_LANG, null);
        if ($default) {
            return $default;
        }
        
        // Fallback: find from languages array
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if (!empty($lang['is_default'])) {
                return $lang['code'];
            }
        }
        
        // Fallback: first language
        if (!empty($languages)) {
            return $languages[0]['code'];
        }
        
        return null;
    }
    
    /**
     * @inheritDoc
     */
    public function get_post_language($post_id) {
        if (!taxonomy_exists(self::TAXONOMY)) {
            $this->register_taxonomy();
        }
        $terms = wp_get_object_terms($post_id, self::TAXONOMY, ['fields' => 'slugs']);
        
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0];
        }
        
        // Fallback: check translation groups DB table (handles products or
        // posts whose taxonomy term was not set due to earlier bugs)
        global $wpdb;
        $table = $this->get_table_name();
        $lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM {$table} WHERE post_id = %d LIMIT 1",
            $post_id
        ));
        
        if ($lang) {
            // Auto-heal: set taxonomy term for future lookups
            $this->set_post_language($post_id, $lang);
            return $lang;
        }

        // Fallback auto-heal: check _aitrfoel_translated_from meta (format: "{source_id}_{target_code}")
        $translated_from = get_post_meta($post_id, '_aitrfoel_translated_from', true);
        if (is_string($translated_from) && strpos($translated_from, '_') !== false) {
            $parts = explode('_', $translated_from, 2);
            $meta_lang = $parts[1] ?? '';
            if ($meta_lang && $this->language_exists($meta_lang)) {
                $source_id = (int) $parts[0];
                $this->set_post_language($post_id, $meta_lang);
                if ($source_id > 0) {
                    $this->setup_translation_relationship($source_id, $post_id, $meta_lang);
                }
                return $meta_lang;
            }
        }
        
        // Posts without an explicit language are assumed to be in the default language
        return $this->get_default_language();
    }
    
    /**
     * @inheritDoc
     */
    public function set_post_language($post_id, $lang_code) {
        // Ensure the term exists
        if (!term_exists($lang_code, self::TAXONOMY)) {
            $lang_name = $lang_code;
            $languages = $this->get_stored_languages();
            foreach ($languages as $lang) {
                if ($lang['code'] === $lang_code) {
                    $lang_name = $lang['name'];
                    break;
                }
            }
            wp_insert_term($lang_code, self::TAXONOMY, ['slug' => $lang_code, 'description' => $lang_name]);
        }
        
        $result = wp_set_object_terms($post_id, $lang_code, self::TAXONOMY);
        return !is_wp_error($result);
    }
    
    /**
     * @inheritDoc
     */
    public function get_post_translations($post_id) {
        $post_id = (int) $post_id;

        // Per-request memo: this is called many times per page (menus, permalinks,
        // product/link localization, dedupe), so avoid re-running 2 queries each time.
        if (isset(self::$translations_memo[$post_id])) {
            return self::$translations_memo[$post_id];
        }

        global $wpdb;
        $table = $this->get_table_name();

        // Get the group_id for this post
        $group_id = $this->get_post_group_id($post_id);
        if (!$group_id) {
            // If no group, return just this post with its language
            $lang = $this->get_post_language($post_id);
            $result = $lang ? [$lang => $post_id] : [];
            self::$translations_memo[$post_id] = $result;
            return $result;
        }

        // Get all posts in this group
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, language_code FROM {$table} WHERE group_id = %d",
            $group_id
        ));

        $translations = [];
        foreach ($results as $row) {
            // Verify the post still exists and is not trashed/deleted
            $post_status = get_post_status($row->post_id);
            if ($post_status && $post_status !== 'trash') {
                $translations[$row->language_code] = intval($row->post_id);
            }
        }

        // Memoize for every member of the group, since they share translations.
        foreach ($translations as $member_id) {
            self::$translations_memo[(int) $member_id] = $translations;
        }
        self::$translations_memo[$post_id] = $translations;

        return $translations;
    }

    /**
     * Clear the per-request translation memo. Call after any change to the
     * translation-group table so stale relationships aren't served.
     */
    public function flush_translations_memo() {
        self::$translations_memo = [];
    }
    
    /**
     * @inheritDoc
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        global $wpdb;
        $table = $this->get_table_name();
        
        // Normalize language code
        $lang_code = $this->normalize_language_code($target_language);
        if (!$lang_code) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Cannot normalize language: {$target_language}", 'error', $original_post_id);
            }
            return new WP_Error('invalid_language', "Cannot normalize language: {$target_language}");
        }
        
        // Verify language exists in our system
        if (!$this->language_exists($lang_code)) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Language '{$lang_code}' not configured in built-in system.", 'error', $original_post_id);
            }
            return new WP_Error('language_not_found', "Language '{$lang_code}' not configured.");
        }
        
        // Get original's language, set default if missing
        $original_lang = $this->get_post_language($original_post_id);
        if (!$original_lang) {
            $default_lang = $this->get_default_language();
            if ($default_lang) {
                $this->set_post_language($original_post_id, $default_lang);
                $original_lang = $default_lang;
            } else {
                return new WP_Error('no_default_language', 'Cannot determine default language.');
            }
        }
        
        // Set language on translated post
        $this->set_post_language($translated_post_id, $lang_code);
        
        // Get or create translation group
        $group_id = $this->get_post_group_id($original_post_id);
        if (!$group_id) {
            $group_id = $this->get_next_group_id();
            
            // Add original to group
            $wpdb->replace($table, [
                'group_id' => $group_id,
                'post_id' => $original_post_id,
                'language_code' => $original_lang,
            ], ['%d', '%d', '%s']);
        }
        
        // Add/update translated post in the group
        // First remove any existing entry for this post
        $wpdb->delete($table, ['post_id' => $translated_post_id], ['%d']);
        
        // Also remove any existing entry for this language in this group (replacing previous translation)
        $wpdb->delete($table, [
            'group_id' => $group_id,
            'language_code' => $lang_code,
        ], ['%d', '%s']);
        
        // Insert the new relationship
        $inserted = $wpdb->insert($table, [
            'group_id' => $group_id,
            'post_id' => $translated_post_id,
            'language_code' => $lang_code,
        ], ['%d', '%d', '%s']);
        
        if ($inserted === false) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log("Failed to insert translation group entry: " . $wpdb->last_error, 'error', $original_post_id);
            }
            return new WP_Error('db_error', 'Failed to save translation relationship.');
        }

        // Relationship changed — drop the per-request memo so lookups don't go stale.
        $this->flush_translations_memo();

        // First time a custom post type gets a translation, flush rewrite rules once so
        // the language-prefixed CPT permalink (e.g. /en/realisaties/slug/) resolves
        // instead of 404ing. Guarded to run at most once per post type.
        $this->maybe_flush_rules_for_new_cpt_translation($translated_post_id);

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('Built-in relationship created: post %d (%s) → post %d (%s), group %d',
                    $original_post_id, $original_lang, $translated_post_id, $lang_code, $group_id
                ),
                'success',
                $original_post_id,
                $translated_post_id
            );
        }

        return true;
    }

    /**
     * Flush rewrite rules the first time a translation is created for a given custom
     * post type, so its language-prefixed single permalink starts resolving instead of
     * 404ing. Pages, posts and products already have working rules; only public,
     * non-builtin CPTs need this. Runs at most once per post type (tracked in an
     * option), and only when our prefixed rules are registered this request.
     *
     * @param int $translated_post_id
     */
    private function maybe_flush_rules_for_new_cpt_translation($translated_post_id) {
        $post_type = get_post_type($translated_post_id);
        if (!$post_type || in_array($post_type, ['page', 'post', 'product'], true)) {
            return;
        }
        $obj = get_post_type_object($post_type);
        if (!$obj || empty($obj->public) || !empty($obj->_builtin)) {
            return;
        }

        $flushed = get_option('aitrfoel_flushed_translation_cpts', []);
        if (!is_array($flushed)) {
            $flushed = [];
        }
        if (in_array($post_type, $flushed, true)) {
            return; // already flushed for this CPT
        }

        $flushed[] = $post_type;
        update_option('aitrfoel_flushed_translation_cpts', $flushed);

        // Reset our rules version so add_rewrite_rules() also schedules its own flush on
        // the next request (belt-and-suspenders if the immediate flush below runs before
        // some plugin registered its rules this request).
        update_option('aitrfoel_rewrite_rules_version', '');

        // Our prefixed CPT rules are registered on init:25 every request, so by the time
        // a translation is created they are in memory — persist them now.
        flush_rewrite_rules(false);

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('Flushed rewrite rules for first translation of CPT "%s".', $post_type),
                'info', $translated_post_id
            );
        }
    }
    
    /**
     * @inheritDoc
     */
    public function get_translation_status($post_id) {
        $status = [];
        $translations = $this->get_post_translations($post_id);
        $available_languages = $this->get_available_languages();
        
        foreach ($available_languages as $lang) {
            $status[$lang->slug] = [
                'language_name' => $lang->name,
                'language_code' => $lang->slug,
                'translated' => isset($translations[$lang->slug]),
                'post_id' => $translations[$lang->slug] ?? null,
                'edit_url' => isset($translations[$lang->slug]) ? get_edit_post_link($translations[$lang->slug]) : null,
                'view_url' => isset($translations[$lang->slug]) ? get_permalink($translations[$lang->slug]) : null,
                'status' => isset($translations[$lang->slug]) ? get_post_status($translations[$lang->slug]) : null,
            ];
        }
        
        return $status;
    }
    
    /**
     * @inheritDoc
     */
    public function language_exists($lang_code) {
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if ($lang['code'] === $lang_code || $lang['slug'] === $lang_code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconcile translation links so the two linking mechanisms agree:
     * the `_aitrfoel_source_page` post meta and the translation-groups table
     * (plus the `aitrfoel_language` term). Fixes "orphaned" translations that
     * appear as their own source in the dashboard and break the cart/checkout
     * page swap, by rebuilding consistent groups from whichever signal exists.
     *
     * @param bool $apply When false, only report what would change (dry run).
     * @return array Report with counts and any items needing manual review.
     */
    public function repair_translation_links($apply = true) {
        global $wpdb;
        $table        = $this->get_table_name();
        $default_lang = $this->get_default_language();

        $report = [
            'applied'             => (bool) $apply,
            'components_repaired' => 0,
            'group_rows_written'  => 0,
            'metas_written'       => 0,
            'language_terms_set'  => 0,
            'parents_remapped'    => 0,
            'group_langs_fixed'   => 0,
            'conflicts'           => [],
            'orphans'             => [],
        ];

        // 1. Source-page meta links: translation_id => source_id.
        $meta_source = [];
        $meta_rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_aitrfoel_source_page'"
        );
        foreach ($meta_rows as $r) {
            $tid = (int) $r->post_id;
            $sid = (int) $r->meta_value;
            if ($sid > 0 && $sid !== $tid) {
                $meta_source[$tid] = $sid;
            }
        }

        // 2. Existing group memberships: group_id => [post_id, ...].
        $groups = [];
        $group_rows = $wpdb->get_results("SELECT group_id, post_id FROM {$table}");
        foreach ($group_rows as $r) {
            $groups[(int) $r->group_id][] = (int) $r->post_id;
        }

        // 3. Union-find over both signals.
        $parent = [];
        $add = function ($x) use (&$parent) {
            if (!isset($parent[$x])) {
                $parent[$x] = $x;
            }
        };
        $find = function ($x) use (&$parent) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $union = function ($a, $b) use (&$parent, $add, $find) {
            $add($a);
            $add($b);
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        foreach ($meta_source as $tid => $sid) {
            $union($tid, $sid);
        }
        foreach ($groups as $members) {
            $first = null;
            foreach ($members as $m) {
                $add($m);
                if ($first === null) {
                    $first = $m;
                } else {
                    $union($first, $m);
                }
            }
        }

        // 4. Build components and repair each.
        $components = [];
        foreach (array_keys($parent) as $node) {
            $components[$find($node)][] = $node;
        }

        foreach ($components as $members) {
            $members = array_values(array_unique($members));
            if (count($members) < 2) {
                continue;
            }

            // Skip members that no longer exist / are trashed.
            $members = array_values(array_filter($members, function ($m) {
                $status = get_post_status($m);
                return $status && $status !== 'trash' && $status !== 'auto-draft';
            }));
            if (count($members) < 2) {
                continue;
            }

            $member_lang = [];
            foreach ($members as $m) {
                $member_lang[$m] = $this->get_post_language($m);
            }

            // Source = default-language original (no source meta), else no-meta member, else oldest.
            $source_id = 0;
            foreach ($members as $m) {
                if ($member_lang[$m] === $default_lang && empty($meta_source[$m])) {
                    $source_id = $m;
                    break;
                }
            }
            if (!$source_id) {
                foreach ($members as $m) {
                    if (empty($meta_source[$m])) {
                        $source_id = $m;
                        break;
                    }
                }
            }
            if (!$source_id) {
                $sorted = $members;
                usort($sorted, function ($a, $b) {
                    return strcmp((string) get_post_field('post_date', $a), (string) get_post_field('post_date', $b));
                });
                $source_id = $sorted[0];
            }

            $repaired_any = false;
            $source_group = $this->get_post_group_id($source_id);

            // Source missing its (default) language term?
            if (!$this->get_post_language($source_id)) {
                $report['language_terms_set']++;
                $repaired_any = true;
                if ($apply) {
                    $this->set_post_language($source_id, $default_lang);
                    $source_group = $this->get_post_group_id($source_id);
                }
            }

            // A genuine conflict is two members sharing the SAME language — not a
            // member that merely happens to be in the default language. After the
            // default language is switched, the translations legitimately become the
            // default-language version, so comparing to $default_lang would falsely
            // flag every one of them. Compare to the source's own language instead.
            $source_lang = isset($member_lang[$source_id]) && $member_lang[$source_id]
                ? $member_lang[$source_id]
                : $default_lang;

            foreach ($members as $m) {
                if ($m === $source_id) {
                    continue;
                }
                $lang = $member_lang[$m];
                if (!$lang || $lang === $source_lang) {
                    // Check if _aitrfoel_translated_from specifies a different language
                    $translated_from = get_post_meta($m, '_aitrfoel_translated_from', true);
                    if (is_string($translated_from) && strpos($translated_from, '_') !== false) {
                        $parts = explode('_', $translated_from, 2);
                        $meta_lang = $parts[1] ?? '';
                        if ($meta_lang && $meta_lang !== $source_lang && $this->language_exists($meta_lang)) {
                            $lang = $meta_lang;
                            $member_lang[$m] = $meta_lang;
                            if ($apply) {
                                $this->set_post_language($m, $meta_lang);
                            }
                        }
                    }
                }
                if (!$lang || $lang === $source_lang) {
                    $report['conflicts'][] = [
                        'post_id' => $m,
                        'title'   => get_the_title($m),
                        'reason'  => 'Shares the source language — genuine duplicate, cannot auto-link.',
                    ];
                    continue;
                }

                // Count/fix only genuine discrepancies.
                $m_group    = $this->get_post_group_id($m);
                $needs_group = ($source_group === null) || ($m_group === null) || ((int) $m_group !== (int) $source_group);
                $needs_meta  = ((int) get_post_meta($m, '_aitrfoel_source_page', true) !== $source_id);

                if ($needs_group) {
                    $report['group_rows_written']++;
                    $repaired_any = true;
                    if ($apply) {
                        $res = $this->setup_translation_relationship($source_id, $m, $lang);
                        if (!is_wp_error($res) && $source_group === null) {
                            $source_group = $this->get_post_group_id($source_id);
                        }
                    }
                }
                if ($needs_meta) {
                    $report['metas_written']++;
                    $repaired_any = true;
                    if ($apply) {
                        update_post_meta($m, '_aitrfoel_source_page', $source_id);
                    }
                }
            }

            if ($repaired_any) {
                $report['components_repaired']++;
            }
        }

        // 5. Orphans: posts with a non-default language term but no link anywhere.
        $orphan_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.object_id AS post_id, t.slug AS lang
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id",
            self::TAXONOMY
        ));
        foreach ($orphan_rows as $r) {
            $pid = (int) $r->post_id;
            if ($r->lang === $default_lang || isset($parent[$pid])) {
                continue; // default language, or already linked in a component.
            }
            $status = get_post_status($pid);
            if (!$status || $status === 'trash' || $status === 'auto-draft') {
                continue;
            }
            if (count($report['orphans']) < 100) {
                $report['orphans'][] = [
                    'post_id' => $pid,
                    'title'   => get_the_title($pid),
                    'lang'    => strtoupper($r->lang),
                    'type'    => get_post_type($pid),
                ];
            }
        }

        // 6. Diagnostic: report the link state of the WooCommerce cart/checkout
        //    pages specifically, since their swap depends on it.
        $report['wc_pages'] = [];
        if (class_exists('WooCommerce')) {
            $wc_page_opts = [
                'cart'     => 'woocommerce_cart_page_id',
                'checkout' => 'woocommerce_checkout_page_id',
            ];
            foreach ($wc_page_opts as $label => $opt) {
                // Read the real configured page ID (bypass our pre_option swap).
                remove_filter('pre_option_' . $opt, [$this, 'filter_translated_page_option'], 10);
                $pid = (int) get_option($opt);
                add_filter('pre_option_' . $opt, [$this, 'filter_translated_page_option'], 10, 2);
                if ($pid <= 0) {
                    continue;
                }
                $tr = $this->get_post_translations($pid);
                $tr_str = [];
                foreach ($tr as $lc => $tid) {
                    $tr_str[] = strtoupper($lc) . '#' . (int) $tid;
                }
                $report['wc_pages'][] = [
                    'label'        => $label,
                    'page_id'      => $pid,
                    'title'        => get_the_title($pid),
                    'lang'         => strtoupper($this->get_post_language($pid) ?: '?'),
                    'translations' => $tr_str,
                ];
            }
        }

        // 7b. Reconcile the group table's language_code with each post's authoritative
        //     language term. get_post_translations() reads the table while routing and
        //     permalinks read the term — if they disagree, the switcher and the default
        //     migration build wrong-language URLs. The term is authoritative (it drives
        //     rendering), so any out-of-sync table row is corrected to match it.
        $gl_rows = $wpdb->get_results("SELECT post_id, language_code FROM {$table}");
        foreach ((array) $gl_rows as $gr) {
            $pid       = (int) $gr->post_id;
            $table_lng = (string) $gr->language_code;
            $term_lng  = $this->get_post_language($pid); // term-first (auto-heals if missing)
            if ($term_lng && $term_lng !== $table_lng) {
                $report['group_langs_fixed']++;
                if ($apply) {
                    $wpdb->update($table, ['language_code' => $term_lng], ['post_id' => $pid]);
                }
            }
        }

        // 8. Parent hierarchy: re-attach translated children to the translated parent.
        //    A translated child whose post_parent is still the source-language parent
        //    is moved under the parent's translation in the child's own language, so
        //    the hierarchy and URL (/de/eltern/kind/) follow the translation.
        $children = $wpdb->get_results(
            "SELECT ID, post_parent FROM {$wpdb->posts}
             WHERE post_parent > 0 AND post_status NOT IN ('trash', 'auto-draft')"
        );
        foreach ((array) $children as $c) {
            $child_id  = (int) $c->ID;
            $parent_id = (int) $c->post_parent;
            if (!$parent_id) {
                continue;
            }
            $child_lang  = $this->get_post_language($child_id);
            $parent_lang = $this->get_post_language($parent_id);
            // Only act on a translated child whose parent is in a different language.
            if (!$child_lang || $child_lang === $default_lang || $parent_lang === $child_lang) {
                continue;
            }
            $parent_translations = $this->get_post_translations($parent_id);
            if (empty($parent_translations[$child_lang])) {
                continue; // parent not translated into this language yet
            }
            $new_parent = (int) $parent_translations[$child_lang];
            if ($new_parent === $parent_id || $new_parent === $child_id) {
                continue;
            }
            $report['parents_remapped']++;
            if ($apply) {
                wp_update_post(['ID' => $child_id, 'post_parent' => $new_parent]);
            }
        }

        $this->flush_translations_memo();

        return $report;
    }

    /**
     * Rewrite [elementor-template id="X"] shortcodes in non-default-language posts
     * so each id references the per-language translated template. Fixes legacy
     * translated posts that still embed the source-language template id.
     *
     * Split out from repair_translation_links() so site owners can run it on its
     * own from Tools. Returns a report compatible with the Repair Links UI keys.
     *
     * @param bool $apply When false, only previews (dry run).
     * @return array
     */
    public function repair_template_shortcodes($apply = true) {
        global $wpdb;

        $report = [
            'applied'                    => (bool) $apply,
            'shortcode_template_ids_fixed' => 0,
            'shortcode_template_post_ids'  => [],
        ];

        $default_lang = $this->get_default_language();

        $shortcode_rows = $wpdb->get_results(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status NOT IN ('trash','auto-draft','inherit')
             AND post_content LIKE '%[elementor-template%'"
        );
        if (!empty($shortcode_rows) && class_exists('AITRFOEL_Translation_Engine')) {
            $engine = AITRFOEL_Translation_Engine::get_instance();
            foreach ($shortcode_rows as $row) {
                $pid  = (int) $row->ID;
                $lang = $this->get_post_language($pid);
                if (!$lang || $lang === $default_lang) {
                    continue; // only rewrite translated posts
                }
                $post = get_post($pid);
                if (!$post || !is_string($post->post_content) || $post->post_content === '') {
                    continue;
                }
                $rewritten = $engine->rewrite_elementor_template_shortcode_ids($post->post_content, $lang);
                if ($rewritten === $post->post_content) {
                    continue; // nothing to change for this post
                }
                $report['shortcode_template_ids_fixed']++;
                if (count($report['shortcode_template_post_ids']) < 100) {
                    $report['shortcode_template_post_ids'][] = [
                        'post_id' => $pid,
                        'title'   => get_the_title($pid),
                        'lang'    => strtoupper($lang),
                    ];
                }
                if ($apply) {
                    wp_update_post([
                        'ID'           => $pid,
                        'post_content' => $rewritten,
                    ]);
                }
            }
        }

        $this->flush_translations_memo();

        return $report;
    }

    /**
     * Loops through all translated posts and re-localizes internal links inside their Elementor data.
     *
     * @param bool $apply When false, only previews (dry run).
     * @return array
     */
    public function repair_content_links($apply = true) {
        global $wpdb;

        $report = [
            'applied'             => (bool) $apply,
            'pages_scanned'       => 0,
            'pages_repaired'      => 0,
            'repaired_pages_info' => [],
        ];

        // Fetch all posts that have _aitrfoel_source_page meta set (which designates translated posts)
        $rows = $wpdb->get_results(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_aitrfoel_source_page'"
        );

        if (!empty($rows) && class_exists('AITRFOEL_Translation_Engine')) {
            $engine = AITRFOEL_Translation_Engine::get_instance();
            foreach ($rows as $row) {
                $pid = (int) $row->post_id;
                $post = get_post($pid);
                if (!$post || $post->post_status === 'trash' || $post->post_status === 'auto-draft' || $post->post_status === 'inherit') {
                    continue; // Skip trashed/revision/auto-draft/deleted posts
                }
                
                $lang = $this->get_post_language($pid);
                if (!$lang) {
                    continue;
                }

                $report['pages_scanned']++;
                $modified = false;

                // 1. Repair Elementor JSON Data if exists
                $data = null;
                $original_data = null;
                $elementor_data_raw = get_post_meta($pid, '_elementor_data', true);
                if (!empty($elementor_data_raw)) {
                    $data = json_decode($elementor_data_raw, true);
                    if (!$data) {
                        $data = json_decode(wp_unslash($elementor_data_raw), true);
                    }
                    if ($data) {
                        $original_data = $data;
                        $engine->localize_internal_links($data, $lang);
                        if ($data !== $original_data) {
                            $modified = true;
                        }
                    }
                }

                // 2. Repair Hrefs in standard post_content
                $original_content = $post->post_content;
                $repaired_content = $engine->localize_hrefs_in_html($original_content, $lang);
                if ($repaired_content !== $original_content) {
                    $modified = true;
                }

                if (!$modified) {
                    continue; // No changes needed
                }

                $report['pages_repaired']++;
                if (count($report['repaired_pages_info']) < 100) {
                    $report['repaired_pages_info'][] = [
                        'post_id' => $pid,
                        'title'   => $post->post_title,
                        'lang'    => strtoupper($lang),
                    ];
                }

                if ($apply) {
                    // Update Elementor Data
                    if ($data && $data !== $original_data) {
                        $json_value = wp_slash(wp_json_encode($data));
                        update_post_meta($pid, '_elementor_data', $json_value);
                    }

                    // Update standard post_content
                    if ($repaired_content !== $original_content) {
                        $wpdb->update(
                            $wpdb->posts,
                            ['post_content' => $repaired_content],
                            ['ID' => $pid]
                        );
                        clean_post_cache($pid);
                    }
                    
                    // Clear Elementor CSS cache for this page so it gets re-generated
                    if ($data && class_exists('\Elementor\Core\Files\CSS\Post')) {
                        $css_file = new \Elementor\Core\Files\CSS\Post($pid);
                        $css_file->delete();
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Constrain WordPress's adjacent-post query (Previous/Next post navigation) to
     * the language of the post being viewed. Excludes any candidate that carries a
     * different language term, so neighbours are always same-language. Posts with no
     * language term (assumed default language) stay eligible for default-language
     * navigation.
     *
     * Hooked on get_previous_post_where / get_next_post_where.
     *
     * @param string  $where
     * @param bool    $in_same_term
     * @param array   $excluded_terms
     * @param string  $taxonomy
     * @param WP_Post $post
     * @return string
     */
    public function filter_adjacent_post_where($where, $in_same_term = false, $excluded_terms = [], $taxonomy = '', $post = null) {
        if (!self::is_enabled() || !($post instanceof WP_Post)) {
            return $where;
        }

        $other_ttids = $this->get_other_language_ttids($post->ID);
        if (empty($other_ttids)) {
            return $where;
        }

        global $wpdb;
        $ids = implode(',', array_map('intval', $other_ttids));
        // WordPress aliases the posts table as `p` in get_adjacent_post().
        $where .= " AND p.ID NOT IN (SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ($ids))";

        return $where;
    }

    /**
     * Term-taxonomy ids for every language EXCEPT the language of the given post.
     * Used to exclude other-language posts from adjacent-post navigation.
     *
     * @param int $post_id
     * @return int[]
     */
    private function get_other_language_ttids($post_id) {
        $current = $this->get_post_language($post_id); // slug, or default
        $terms   = get_terms([
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || count($terms) < 2) {
            return [];
        }

        $others = [];
        foreach ($terms as $term) {
            if ($term->slug === $current) {
                continue;
            }
            $others[] = (int) $term->term_taxonomy_id;
        }
        return $others;
    }

    /**
     * Scope a JetEngine listing / query-builder WP_Query to the current language by
     * excluding posts tagged with any OTHER language. Posts in the current language
     * (or untagged, assumed default) stay visible. Only applied to post types that use
     * our language taxonomy, so non-translatable listings are untouched.
     *
     * @param array $args WP_Query args.
     * @return array
     */
    public function filter_jetengine_query_lang($args) {
        if (is_admin() && !wp_doing_ajax()) {
            return $args;
        }
        if (!$this->is_active() || !is_array($args)) {
            return $args;
        }

        $current = $this->get_current_language();
        if (!$current) {
            return $args;
        }
        // Need at least two languages for collapsing to matter.
        if (count($this->get_stored_languages()) < 2) {
            return $args;
        }

        // Only act on post types that use our language taxonomy.
        $post_types = isset($args['post_type']) ? (array) $args['post_type'] : [];
        $applies = empty($post_types); // unknown type → best-effort
        foreach ($post_types as $pt) {
            if ($pt && in_array(self::TAXONOMY, get_object_taxonomies($pt), true)) {
                $applies = true;
                break;
            }
        }
        if (!$applies) {
            return $args;
        }

        // Filter queries strictly by language using taxonomy query.
        // This ensures correct post counts (found_posts) and fixes pagination.
        if (!isset($args['tax_query'])) {
            $args['tax_query'] = [];
        }
        $args['tax_query'][] = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => [$current],
            'operator' => 'IN',
        ];

        // Keep the the_posts collapsing filter as a fallback for custom queries or grids
        // where SQL tax_query is bypassed (removes cross-language duplicates).
        add_filter('the_posts', [$this, 'localize_jetengine_listing_posts'], 20, 2);

        return $args;
    }

    /**
     * Scope frontend queries (WP_Query loops) dynamically by language using tax_query.
     * This acts as a global catch-all to prevent third-party filtering/pagination
     * plugins (like JetSmartFilters) from pulling posts of different languages
     * during custom AJAX pagination and grid updates.
     *
     * @param WP_Query $query The query object.
     */
    public function filter_pre_get_posts($query) {
        if (is_admin() && !wp_doing_ajax()) {
            return;
        }
        if (!$this->is_active()) {
            return;
        }

        $current = $this->get_current_language();
        if (!$current) {
            return;
        }
        if (count($this->get_stored_languages()) < 2) {
            return;
        }

        // Only filter queries for post types that use our language taxonomy
        $post_type = $query->get('post_type');
        if (empty($post_type)) {
            return;
        }
        $post_types = (array) $post_type;

        // Bypass media attachments or query-attachments AJAX requests
        if (in_array('attachment', $post_types, true) || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'query-attachments')) {
            return;
        }

        // Template / theme builder post types must NEVER be restricted by language tax_query.
        // Elementor Pro Theme Builder, Header Footer Builder, and JetThemeCore select templates
        // dynamically based on display conditions and swap them at render time via location/builder filters.
        $template_types = apply_filters('aitrfoel_template_post_types', [
            'elementor_library',
            'elementor-hf',
            'jet-theme-core',
            'jet-engine',
            'e-floating-buttons',
            'elementor_snippet',
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
        ]);
        foreach ($post_types as $pt) {
            if (in_array($pt, $template_types, true)) {
                return;
            }
        }

        $applies = false;
        foreach ($post_types as $pt) {
            if ($pt && in_array(self::TAXONOMY, get_object_taxonomies($pt), true)) {
                $applies = true;
                break;
            }
        }
        if (!$applies) {
            return;
        }

        // Bypass for single post lookups by ID, explicit post__in lists, or menu items
        if ($query->get('p') || $query->get('page_id') || $query->get('post__in') || $query->get('post_type') === 'nav_menu_item') {
            return;
        }

        // Add tax_query for language
        $tax_query = $query->get('tax_query');
        if (!is_array($tax_query)) {
            $tax_query = [];
        }

        // Check if language taxonomy query is already present to avoid duplicates
        $has_lang_query = false;
        foreach ($tax_query as $tq) {
            if (is_array($tq) && isset($tq['taxonomy']) && $tq['taxonomy'] === self::TAXONOMY) {
                $has_lang_query = true;
                break;
            }
        }

        if (!$has_lang_query) {
            $default_lang = $this->get_default_language();
            if ($current === $default_lang) {
                // In default language, exclude non-default language posts (NOT IN)
                // so that legacy/untranslated posts without explicit taxonomy terms remain visible.
                $non_default_langs = [];
                foreach ($this->get_stored_languages() as $l) {
                    if ($l['code'] !== $default_lang) {
                        $non_default_langs[] = $l['code'];
                    }
                }
                if (!empty($non_default_langs)) {
                    $tax_query[] = [
                        'taxonomy'         => self::TAXONOMY,
                        'field'            => 'slug',
                        'terms'            => $non_default_langs,
                        'operator'         => 'NOT IN',
                        'include_children' => false,
                    ];
                    $query->set('tax_query', $tax_query);
                }
            } else {
                // In non-default language, only include posts tagged with the current language.
                $tax_query[] = [
                    'taxonomy'         => self::TAXONOMY,
                    'field'            => 'slug',
                    'terms'            => [$current],
                    'operator'         => 'IN',
                    'include_children' => false,
                ];
                $query->set('tax_query', $tax_query);
            }
        }
    }

    /**
     * Collapse a JetEngine listing result set to one post per translation group:
     * the published current-language translation when it exists, otherwise the
     * original (fallback) — so the grid never shows duplicates and is never empty
     * just because a translation is still a draft. Other-language items with no
     * current-language version are dropped. Runs once per JetEngine query.
     *
     * @param array    $posts
     * @param WP_Query $query
     * @return array
     */
    public function localize_jetengine_listing_posts($posts, $query) {
        remove_filter('the_posts', [$this, 'localize_jetengine_listing_posts'], 20);

        if (empty($posts) || !is_array($posts)) {
            return $posts;
        }
        $current = $this->get_current_language();
        $default = $this->get_default_language();
        if (!$current) {
            return $posts;
        }

        // Index the raw result by ID so we can read each group's source post date
        // without extra queries (the unfiltered query returns source + translations).
        $by_id = [];
        foreach ($posts as $p) {
            if (is_object($p) && !empty($p->ID)) {
                $by_id[(int) $p->ID] = $p;
            }
        }

        // When the listing is date-ordered (the default), translation copies carry
        // their own creation date, which scrambles the order once we swap them in.
        // Re-sort by each group's SOURCE date so the listing keeps the original
        // order regardless of when translations were created. Custom orderings
        // (title, menu_order, meta) are left untouched.
        $orderby      = is_object($query) ? $query->get('orderby') : '';
        $order        = (is_object($query) && strtoupper((string) $query->get('order')) === 'ASC') ? 'ASC' : 'DESC';
        $sort_by_date = ($orderby === '' || $orderby === 'date' || $orderby === 'post_date');

        $out         = [];
        $seen_groups = [];
        foreach ($posts as $p) {
            if (!is_object($p) || empty($p->ID)) {
                $out[] = ['post' => $p, 'date' => ''];
                continue;
            }
            $translations = $this->get_post_translations($p->ID);
            // Group identity = the default-language member (or this post if none).
            $group_key = isset($translations[$default]) ? (int) $translations[$default] : (int) $p->ID;
            if (isset($seen_groups[$group_key])) {
                continue; // group already represented
            }

            $chosen = null;
            if (isset($translations[$current]) && get_post_status($translations[$current]) === 'publish') {
                $chosen = get_post((int) $translations[$current]); // current language
            } else {
                $lang = $this->get_post_language($p->ID);
                // Fallback to the original/source (or an untagged post); skip stray
                // other-language items that have no current-language version.
                if (!$lang || $lang === $default || $lang === $current) {
                    $chosen = $p;
                }
            }

            if ($chosen) {
                $seen_groups[$group_key] = true;
                // Sort key = the source (default-language) post's date when available,
                // so all translations of a group sort to the source's position.
                $source_date = isset($by_id[$group_key]) ? $by_id[$group_key]->post_date : $chosen->post_date;
                $out[] = ['post' => $chosen, 'date' => (string) $source_date];
            }
        }

        if ($sort_by_date) {
            usort($out, function ($a, $b) use ($order) {
                $cmp = strcmp($a['date'], $b['date']);
                return $order === 'ASC' ? $cmp : -$cmp;
            });
        }

        return array_map(function ($row) { return $row['post']; }, $out);
    }

    /**
     * Localize an Astra header-builder custom button link (theme_mod value) to the
     * current language's translation of the linked page. Accepts both Astra's array
     * shape (['url' => ...]) and a plain URL string.
     *
     * @param mixed $value
     * @return mixed
     */
    /**
     * Start buffering the theme header so its internal links can be localized.
     * Only buffers on a non-default language.
     */
    public function start_header_link_localization() {
        if ($this->header_ob_active || !$this->is_active()) {
            return;
        }
        $current = $this->get_current_language();
        if ($current && $current !== $this->get_default_language()) {
            ob_start();
            $this->header_ob_active = true;
        }
    }

    /**
     * Flush the buffered header through the internal-link localizer, so theme-rendered
     * links (Astra header buttons, custom HTML, etc.) point at the current-language
     * translations.
     */
    public function end_header_link_localization() {
        if (!$this->header_ob_active) {
            return;
        }
        $this->header_ob_active = false;
        $html = ob_get_clean();
        echo $this->localize_internal_links($html); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme header HTML from the output buffer; only internal link URLs are rewritten
    }

    public function localize_astra_button_link($value) {
        if (is_admin() || !$this->is_active()) {
            return $value;
        }

        if (is_array($value) && !empty($value['url']) && is_string($value['url'])) {
            $localized = $this->localize_single_internal_url($value['url']);
            if ($localized) {
                $value['url'] = $localized;
            }
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $localized = $this->localize_single_internal_url($value);
            if ($localized) {
                return $localized;
            }
        }

        return $value;
    }

    /**
     * Resolve a single internal URL to the current-language translation's permalink,
     * or return null when no swap applies (default language, external URL, no
     * translation, etc.). Shared by header-button / theme-element localizers.
     *
     * @param string $url
     * @return string|null
     */
    private function localize_single_internal_url($url) {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $current = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$current || $current === $default) {
            return null;
        }

        $raw_home = rtrim((string) get_option('home'), '/');
        if ($raw_home === '' || strpos($url, $raw_home) !== 0) {
            return null; // not an internal absolute URL
        }

        $post_id = $this->resolve_internal_url_to_post($url);
        if (!$post_id) {
            return null;
        }
        $translations = $this->get_post_translations($post_id);
        if (isset($translations[$current]) && get_post_status($translations[$current]) === 'publish') {
            $permalink = get_permalink((int) $translations[$current]);
            return $permalink ?: null;
        }
        return null;
    }

    /**
     * @inheritDoc
     */
    public function get_language_code($our_language) {
        return $this->normalize_language_code($our_language);
    }
    
    /**
     * @inheritDoc
     */
    public function remove_translation_relationship($post_id) {
        global $wpdb;
        $table = $this->get_table_name();
        
        try {
            // Remove this post from its translation group
            $wpdb->delete($table, ['post_id' => $post_id], ['%d']);
            
            // Remove language taxonomy
            wp_set_object_terms($post_id, [], self::TAXONOMY);

            // Relationship changed — drop the per-request memo.
            $this->flush_translations_memo();

            return true;
        } catch (Exception $e) {
            if (class_exists('AITRFOEL_Logger')) {
                AITRFOEL_Logger::log(
                    sprintf('Failed to remove translation relationship for post %d: %s', $post_id, $e->getMessage()),
                    'error',
                    $post_id
                );
            }
            return false;
        }
    }
    
    /**
     * @inheritDoc
     */
    public function get_pages_translation_status() {
        if (!$this->is_active()) {
            return [];
        }
        
        $post_types = ['page', 'post'];
        if (class_exists('WooCommerce') && get_option('aitrfoel_woocommerce_support', false)) {
            $post_types[] = 'product';
        }
        $pages = get_posts([
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        
        $pages_status = [];
        
        foreach ($pages as $page) {
            $translation_status = $this->get_translation_status($page->ID);
            $elementor_data = get_post_meta($page->ID, '_elementor_data', true);
            
            $pages_status[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'status' => $page->post_status,
                'edit_url' => get_edit_post_link($page->ID),
                'view_url' => get_permalink($page->ID),
                'translations' => $translation_status,
                'has_elementor' => !empty($elementor_data),
            ];
        }
        
        return $pages_status;
    }
    
    /**
     * @inheritDoc
     */
    public function get_language_switcher_data() {
        if (!$this->is_active()) {
            return [];
        }
        
        $languages = $this->get_available_languages();
        $default_lang = $this->get_default_language();
        $current_post_id = get_queried_object_id();
        $translations = $current_post_id ? $this->get_post_translations($current_post_id) : [];
        $hide_untranslated = get_option('aitrfoel_switcher_hide_untranslated', false);
        
        $switcher_data = [];
        
        foreach ($languages as $lang) {
            $has_translation = isset($translations[$lang->slug]);
            
            // Skip untranslated languages if setting is enabled (always show default & current)
            if ($hide_untranslated && $current_post_id && !$has_translation
                && $lang->slug !== $default_lang && $lang->slug !== $this->get_current_language()) {
                continue;
            }
            
            $raw_home = trailingslashit(get_option('home'));
            $default_home = get_option('aitrfoel_default_lang_prefix', false) ? $raw_home . $default_lang . '/' : $raw_home;
            $url = ($lang->slug === $default_lang) ? $default_home : ($raw_home . $lang->slug . '/');
            
            // If we have a translation of the current page, link to it
            if ($has_translation) {
                $url = $this->get_post_permalink($translations[$lang->slug]);
            } elseif ($lang->slug === $default_lang && $current_post_id) {
                // Switching to default language: link to the original post
                // Find the default-language post in the translation group
                if (isset($translations[$default_lang])) {
                    $url = $this->get_post_permalink($translations[$default_lang]);
                } else {
                    // Current post might be the default-language post itself
                    $post_lang = $this->get_post_language($current_post_id);
                    if ($post_lang === $default_lang) {
                        $url = $this->get_post_permalink($current_post_id);
                    }
                }
            }
            
            $switcher_data[] = [
                'code' => $lang->slug,
                'name' => $lang->name,
                'native_name' => $lang->native_name ?? $lang->name,
                'flag' => $lang->flag ?? '',
                'is_default' => $lang->slug === $default_lang,
                'is_current' => $lang->slug === $this->get_current_language(),
                'url' => $url,
            ];
        }
        
        return $switcher_data;
    }
    
    /**
     * Get the correct permalink for a post with language prefix
     * Note: The filter_permalink/filter_page_permalink hooks already add language
     * prefixes when get_permalink() is called, so we just return get_permalink().
     * @inheritDoc
     */
    public function get_post_permalink($post_id) {
        return get_permalink($post_id);
    }
    
    // =========================================================================
    // URL REWRITING & LANGUAGE DETECTION
    // =========================================================================
    
    /**
     * Add rewrite rules for language prefixes
     */
    public function add_rewrite_rules() {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        $default_lang = $this->get_default_language();
        $include_default_prefix = get_option('aitrfoel_default_lang_prefix', false);
        
        $lang_codes = [];
        foreach ($languages as $lang) {
            if ($lang['code'] !== $default_lang || $include_default_prefix) {
                $lang_codes[] = preg_quote($lang['code'], '/');
            }
        }
        
        if (empty($lang_codes)) {
            return;
        }
        
        $lang_pattern = implode('|', $lang_codes);
        
        // Auto-flush rewrite rules when version changes OR when stored rules
        // are missing our language patterns (can happen after AJAX setup, reinstall, etc.)
        $rules_version = '1.6.0'; // Bump this when rewrite rules change
        $stored_version = get_option('aitrfoel_rewrite_rules_version', '');
        $needs_flush = ($stored_version !== $rules_version);
        
        if (!$needs_flush) {
            // Verify our language rules are actually present in stored rewrite rules
            $stored_rules = get_option('rewrite_rules', []);
            if (is_array($stored_rules)) {
                $test_pattern = '^(' . $lang_pattern . ')/?$';
                if (!isset($stored_rules[$test_pattern])) {
                    $needs_flush = true;
                }

                // Also verify every public custom post type has its language-prefixed
                // single rule. CPTs registered after our first pass (JetEngine, etc.)
                // would otherwise be missing, and the catch-all page rule would 404
                // their URLs (e.g. /nl/realisaties/slug/). Auto-heal by flushing once.
                if (!$needs_flush) {
                    $cpts_check = get_post_types(['public' => true, '_builtin' => false], 'objects');
                    foreach ($cpts_check as $cpt_check) {
                        $cpt_slug = isset($cpt_check->rewrite['slug']) ? $cpt_check->rewrite['slug'] : $cpt_check->name;
                        $cpt_slug = trim((string) $cpt_slug, '/');
                        if ($cpt_slug === '') {
                            continue;
                        }
                        $probe = '^(' . $lang_pattern . ')/' . preg_quote($cpt_slug, '/') . '/([^/]+)/?$';
                        if (!isset($stored_rules[$probe])) {
                            $needs_flush = true;
                            break;
                        }
                    }
                }
            }
        }
        
        if ($needs_flush) {
            update_option('aitrfoel_rewrite_rules_version', $rules_version);
            // Schedule flush after all rules are registered
            add_action('wp_loaded', function() {
                flush_rewrite_rules();
            });
        }
        
        // === Rewrite rules use 'top' which adds to extra_rules_top array ===
        // WordPress extra_rules_top uses FIFO: FIRST added = FIRST checked = HIGHEST priority.
        // So we add from HIGHEST priority to LOWEST priority.
        
        // 1. HIGHEST PRIORITY: Custom post type rules (most specific multi-segment patterns)
        // Must be added FIRST so they're checked before the catch-all page rule.
        // Fixes 404 for WooCommerce products and other CPTs with language prefix
        // (e.g., /fr/product/my-product/ or /fr/boutique/produit/my-product/).
        $custom_post_types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        foreach ($custom_post_types as $cpt) {
            $slug = isset($cpt->rewrite['slug']) ? $cpt->rewrite['slug'] : $cpt->name;
            // WooCommerce sets rewrite slug to '/product' (with leading slash) — strip it
            $slug = trim($slug, '/');
            
            if (empty($slug)) {
                continue;
            }

            // Single CPT post: /fr/product/my-product/
            // Use post_type + name (rather than the CPT's query var) so the rule
            // resolves even when the post type was registered with query_var disabled
            // or a non-default query var — this is the universal form WordPress core
            // falls back to. Fixes CPT singles 404ing under the language prefix.
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($slug, '/') . '/([^/]+)/?$',
                'index.php?lang=$matches[1]&post_type=' . $cpt->name . '&name=$matches[2]',
                'top'
            );

            // Hierarchical or nested-permalink CPTs: /fr/slug/parent/child/ (and paged).
            // The query var of a hierarchical post type resolves a nested path, so allow
            // slashes in the captured slug. Added after the single-segment rule so the
            // more specific rule keeps priority for flat CPTs.
            if (is_post_type_hierarchical($cpt->name) || !empty($cpt->rewrite['hierarchical'])) {
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($slug, '/') . '/(.+?)/page/([0-9]+)/?$',
                    'index.php?lang=$matches[1]&' . $cpt->name . '=$matches[2]&paged=$matches[3]',
                    'top'
                );
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($slug, '/') . '/(.+?)/?$',
                    'index.php?lang=$matches[1]&' . $cpt->name . '=$matches[2]',
                    'top'
                );
            }

            // Archive: /fr/product/ (or /fr/shop/)
            if ($cpt->has_archive) {
                $archive_slug = $cpt->has_archive === true ? $slug : $cpt->has_archive;
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($archive_slug, '/') . '/?$',
                    'index.php?lang=$matches[1]&post_type=' . $cpt->name,
                    'top'
                );
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/' . preg_quote($archive_slug, '/') . '/page/([0-9]+)/?$',
                    'index.php?lang=$matches[1]&post_type=' . $cpt->name . '&paged=$matches[2]',
                    'top'
                );
            }
        }
        
        // 2. Tag: /fr/tag/slug/
        // Use get_taxonomy() to get the actual rewrite slug — this correctly handles:
        //   a) Custom tag base set in Settings > Permalinks ($wp_rewrite->tag_base)
        //   b) WordPress core translation of 'tag' base (e.g. $wp_rewrite->tag_base is
        //      empty but _x('tag','tag base') returns a localized slug). When category_base
        //      option is not set, WP uses _x() translation for the base, which $wp_rewrite
        //      does NOT expose — only the taxonomy rewrite['slug'] has the correct value.
        $tag_tax = get_taxonomy('post_tag');
        $tag_base = ($tag_tax && !empty($tag_tax->rewrite['slug']))
            ? trim($tag_tax->rewrite['slug'], '/')
            : 'tag';
        // Pagination first: /fr/tag/slug/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($tag_base, '/') . '/(.+?)/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&tag=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($tag_base, '/') . '/(.+?)/?$',
            'index.php?lang=$matches[1]&tag=$matches[2]',
            'top'
        );

        // 3. Category: /fr/category/slug/ (or /fr/categorie/slug/ on French sites, etc.)
        // Same reasoning: read from get_taxonomy('category')->rewrite['slug'] not $wp_rewrite->category_base,
        // because when category_base option is empty, WP uses _x('category','category base') as the
        // slug — e.g. French core translates it to 'categorie'. $wp_rewrite->category_base stays ''
        // so reading it would fall back to the English 'category' and generate wrong rules.
        $cat_tax = get_taxonomy('category');
        $cat_base = ($cat_tax && !empty($cat_tax->rewrite['slug']))
            ? trim($cat_tax->rewrite['slug'], '/')
            : 'category';
        // Pagination first: /fr/categorie/slug/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($cat_base, '/') . '/(.+?)/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&category_name=$matches[2]&paged=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/' . preg_quote($cat_base, '/') . '/(.+?)/?$',
            'index.php?lang=$matches[1]&category_name=$matches[2]',
            'top'
        );

        // 3b. Custom taxonomy terms — WooCommerce product categories, product tags, etc.
        // Handles: /fr/product-category/slug/ and hierarchical /fr/product-category/parent/child/
        // Must be added before the catch-all page rule but after specific CPT rules.
        $public_taxonomies = get_taxonomies(['public' => true, '_builtin' => false], 'objects');
        foreach ($public_taxonomies as $taxonomy) {
            if (empty($taxonomy->rewrite) || empty($taxonomy->rewrite['slug']) || empty($taxonomy->query_var)) {
                continue;
            }
            $tax_slug = trim($taxonomy->rewrite['slug'], '/');
            if (empty($tax_slug)) {
                continue;
            }
            // Pagination first (higher priority): /fr/product-category/slug/page/2/
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($tax_slug, '/') . '/(.+?)/page/([0-9]+)/?$',
                'index.php?lang=$matches[1]&' . $taxonomy->query_var . '=$matches[2]&paged=$matches[3]',
                'top'
            );
            // Term archive: /fr/product-category/slug/ (supports parent/child hierarchy via .+?)
            add_rewrite_rule(
                '^(' . $lang_pattern . ')/' . preg_quote($tax_slug, '/') . '/(.+?)/?$',
                'index.php?lang=$matches[1]&' . $taxonomy->query_var . '=$matches[2]',
                'top'
            );
        }

        // 4. Feed: /fr/feed/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/feed/(feed|rdf|rss|rss2|atom)/?$',
            'index.php?lang=$matches[1]&feed=$matches[2]',
            'top'
        );
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/feed/?$',
            'index.php?lang=$matches[1]&feed=feed',
            'top'
        );
        
        // 5. Pagination: /fr/page/2/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/page/([0-9]+)/?$',
            'index.php?lang=$matches[1]&paged=$matches[2]',
            'top'
        );
        
        // Search: /fr/?s=query (handled by query var, no special rewrite needed)

        // 5b. Paginated pages with /page/ base: /fr/blog/page/2/
        // Standard WordPress pagination for the posts page and static pages.
        // e.g. /fr/blog/page/2/ → lang=fr, pagename=blog, paged=2
        // Also handles translated pagination bases (e.g. 'pagina' in some locales).
        global $wp_rewrite;
        $pagination_base = (isset($wp_rewrite) && !empty($wp_rewrite->pagination_base))
            ? trim($wp_rewrite->pagination_base, '/')
            : 'page';
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/' . preg_quote($pagination_base, '/') . '/([0-9]+)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]&paged=$matches[3]',
            'top'
        );

        // 5c. Paginated pages WITHOUT /page/ base: /fr/blog/2/
        // Some themes generate blog pagination as /{posts-page-slug}/{num}/ without
        // a 'page' segment in between. The second segment is purely numeric to avoid
        // conflicting with CPT/taxonomy slugs (which are handled earlier with higher priority).
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/([0-9]+)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]&paged=$matches[3]',
            'top'
        );

        // 5d. WooCommerce / plugin endpoint URLs: /fr/page-slug/endpoint/ (and /value/)
        // e.g. /fr/mon-espace-lavie/commandes/, /fr/mon-espace-lavie/editer-adresse/facturation/
        // WooCommerce registers endpoints via add_rewrite_endpoint() at init:20, so
        // $wp_rewrite->endpoints is fully populated by the time we reach init:25 here.
        // Adding explicit rules per endpoint is more reliable than parse_request fixups because
        // WordPress resolves pagename + endpoint query var in one step, matching how it handles
        // these URLs without the lang prefix.
        if (!empty($wp_rewrite->endpoints) && is_array($wp_rewrite->endpoints)) {
            foreach ($wp_rewrite->endpoints as $ep) {
                if (!is_array($ep) || count($ep) < 2) {
                    continue;
                }
                $ep_places = (int) $ep[0];
                $ep_slug   = (string) $ep[1];
                // $ep[2] is always a string by the time WP stores it (never boolean true)
                $ep_qvar   = (isset($ep[2]) && $ep[2] !== '') ? (string) $ep[2] : $ep_slug;

                // Only handle page-based endpoints (EP_PAGES = 4)
                if (!($ep_places & EP_PAGES) || $ep_slug === '') {
                    continue;
                }

                $ep_slug_q = preg_quote($ep_slug, '/');

                // /fr/page-slug/endpoint/value/ — endpoint with an optional value
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/([^/]+)/' . $ep_slug_q . '/([^/]*)/?$',
                    'index.php?lang=$matches[1]&pagename=$matches[2]&' . $ep_qvar . '=$matches[3]',
                    'top'
                );
                // /fr/page-slug/endpoint/ — endpoint without a value
                add_rewrite_rule(
                    '^(' . $lang_pattern . ')/([^/]+)/' . $ep_slug_q . '/?$',
                    'index.php?lang=$matches[1]&pagename=$matches[2]&' . $ep_qvar . '=',
                    'top'
                );
            }
        }

        // 6. Posts with permalink front base: /fr/blog/post-slug/
        // Mirrors common permalink structures like /blog/%postname%/ so prefixed
        // default-language post URLs resolve instead of falling through to pages.
        if (isset($wp_rewrite) && !empty($wp_rewrite->permalink_structure)) {
            $permalink_structure = trim($wp_rewrite->permalink_structure, '/');
            if (strpos($permalink_structure, '%postname%') !== false) {
                $front_base = trim(str_replace('%postname%', '', $permalink_structure), '/');
                if ($front_base !== '' && strpos($front_base, '%') === false) {
                    add_rewrite_rule(
                        '^(' . $lang_pattern . ')/' . preg_quote($front_base, '/') . '/([^/]+)/?$',
                        'index.php?lang=$matches[1]&name=$matches[2]',
                        'top'
                    );
                }
            }
        }

        // 7. Posts with language prefix: /fr/post-slug/ (single-segment slugs)
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/([^/]+)/?$',
            'index.php?lang=$matches[1]&name=$matches[2]',
            'top'
        );
        
        // 8. Language root: /fr/
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/?$',
            'index.php?lang=$matches[1]',
            'top'
        );
        
        // 9. LOWEST PRIORITY: Catch-all for pages/hierarchical content: /fr/parent/child/
        // Must be LAST added so it's checked after all specific patterns above.
        add_rewrite_rule(
            '^(' . $lang_pattern . ')/(.+?)/?$',
            'index.php?lang=$matches[1]&pagename=$matches[2]',
            'top'
        );
    }
    
    /**
     * Register 'lang' as a query variable
     * @param array $vars
     * @return array
     */
    public function register_query_vars($vars) {
        $vars[] = 'lang';
        return $vars;
    }
    
    /**
     * Pre-detects the frontend language on early init.
     * This ensures REST API requests (which bypass parse_request hook)
     * and other AJAX requests have the correct language set early.
     */
    public function init_language_detection() {
        if (!$this->is_active()) {
            return;
        }
        if ($this->current_language) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';
        $q = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }

        $lang = $this->get_language_from_path($uri);
        if (!$lang) {
            $lang = $this->detect_ajax_language();
        }
        if ($lang) {
            $this->current_language = $lang;
        } else {
            $this->current_language = $this->get_default_language();
        }
    }

    /**
     * Detect language from the incoming request and rewrite query variables.
     * Must run on parse_request so it has access to and can modify $wp->query_vars.
     * @param WP $wp
     */
    public function detect_language_from_request($wp) {
        if (!$this->is_active()) {
            return;
        }
        
        if (isset($wp->query_vars['lang'])) {
            $lang = sanitize_text_field($wp->query_vars['lang']);
            if ($this->language_exists($lang)) {
                $this->current_language = $lang;
            }
        }
        
        // If we matched a 'name' query var (single-segment slug), check if it's
        // actually a page rather than a post. WordPress rewrite matched the
        // single-segment rule first (name=), but it may be a page.
        if (isset($wp->query_vars['name']) && isset($wp->query_vars['lang'])) {
            $slug = $wp->query_vars['name'];
            // Check if a page with this slug exists
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page && $page->post_type === 'page') {
                // Switch from post lookup to page lookup
                $wp->query_vars['pagename'] = $slug;
                $wp->query_vars['page_id'] = $page->ID;
                unset($wp->query_vars['name']);
            }
        }

        // Runtime fallback: resolve a prefixed CPT single that fell through to the
        // catch-all page rule (because the CPT's own prefixed rewrite rule wasn't yet
        // flushed). Makes /{lang}/{cpt-slug}/{post-slug}/ work even before a flush.
        $this->maybe_resolve_cpt_from_catchall($wp);
        
        // Language-root URL (e.g. /en/) with a static front page configured:
        // WordPress doesn't know to show the front page because the rewrite rule
        // only sets lang=. Manually point to the static front page (or its translation).
        // IMPORTANT: only apply when the ONLY query var set is 'lang' — if any
        // other content var is set (product_cat, post_type, name, etc.) it means
        // the URL matched a content rule, not the language-root rule, and we must
        // not override it with the front page.
        if (isset($wp->query_vars['lang'])
            && get_option('show_on_front') === 'page'
        ) {
            // Build the set of non-trivial query vars (exclude lang itself and
            // WordPress internal vars that are always present or not content).
            $ignore_vars = ['lang' => 1, 'error' => 1, 'preview' => 1, 'robots' => 1,
                            'favicon' => 1, 'sitemap' => 1, 'sitemap-stylesheet' => 1];
            $content_vars = array_filter(
                array_diff_key($wp->query_vars, $ignore_vars),
                function($v) { return $v !== '' && $v !== false && $v !== null && $v !== 0; }
            );

            if (empty($content_vars)) {
                $front_page_id = (int) get_option('page_on_front');
                if ($front_page_id) {
                    $request_lang = sanitize_text_field($wp->query_vars['lang']);
                    $default_lang = $this->get_default_language();

                    // If visiting a non-default language root, find the translated front page
                    if ($request_lang && $request_lang !== $default_lang) {
                        $translations = $this->get_post_translations($front_page_id);
                        if (isset($translations[$request_lang])) {
                            $front_page_id = $translations[$request_lang];
                        }
                    }

                    $wp->query_vars['page_id'] = $front_page_id;
                }
            }
        }

        // Handle sub-path URLs caught by the catch-all pagename rule that are actually
        // WooCommerce endpoint URLs (or other plugin endpoint URLs registered via
        // add_rewrite_endpoint). The catch-all maps /fr/mon-compte/commandes/ to
        // pagename=mon-compte/commandes. No child page exists so WP 404s.
        // We detect: base segment = real page, remaining segment = registered endpoint slug,
        // then set page_id + endpoint query var so WooCommerce (and other plugins) resolve.
        if (isset($wp->query_vars['lang']) && isset($wp->query_vars['pagename'])) {
            $pagename = $wp->query_vars['pagename'];

            if (strpos($pagename, '/') !== false) {
                // Only act if no real hierarchical page exists at the full path
                $full_page = get_page_by_path($pagename);
                if (!$full_page || $full_page->post_type !== 'page') {
                    // Split into base page slug + the rest (endpoint path)
                    $slash_pos      = strpos($pagename, '/');
                    $base_slug      = substr($pagename, 0, $slash_pos);
                    $endpoint_path  = substr($pagename, $slash_pos + 1);
                    $base_page      = get_page_by_path($base_slug);

                    if ($base_page && $base_page->post_type === 'page') {
                        $matched_qv    = null;
                        $matched_value = '';

                        // 1. Check WooCommerce endpoint slugs (handles translated slugs too)
                        if (function_exists('WC') && WC()->query) {
                            foreach (WC()->query->get_query_vars() as $query_var => $slug) {
                                if (!is_string($slug) || $slug === '') {
                                    continue;
                                }
                                if ($endpoint_path === $slug) {
                                    $matched_qv    = $query_var;
                                    $matched_value = '';
                                    break;
                                }
                                // Endpoint with sub-value: editer-adresse/facturation
                                if (strpos($endpoint_path, $slug . '/') === 0) {
                                    $matched_qv    = $query_var;
                                    $matched_value = substr($endpoint_path, strlen($slug) + 1);
                                    break;
                                }
                            }
                        }

                        // 2. Fallback: check any endpoint registered via add_rewrite_endpoint()
                        //    (WooCommerce, WP core, other plugins). Stored in $wp_rewrite->endpoints
                        //    as [bitmask, slug, query_var].
                        if ($matched_qv === null) {
                            global $wp_rewrite;
                            if (!empty($wp_rewrite->endpoints) && is_array($wp_rewrite->endpoints)) {
                                foreach ($wp_rewrite->endpoints as $ep) {
                                    $slug = isset($ep[1]) ? (string) $ep[1] : '';
                                    $qvar = isset($ep[2]) ? (string) $ep[2] : $slug;
                                    if ($slug === '') {
                                        continue;
                                    }
                                    if ($endpoint_path === $slug) {
                                        $matched_qv    = $qvar;
                                        $matched_value = '';
                                        break;
                                    }
                                    if (strpos($endpoint_path, $slug . '/') === 0) {
                                        $matched_qv    = $qvar;
                                        $matched_value = substr($endpoint_path, strlen($slug) + 1);
                                        break;
                                    }
                                }
                            }
                        }

                        if ($matched_qv !== null) {
                            $page_id = (int) $base_page->ID;

                            // Translate page to current language if not default
                            $request_lang = sanitize_text_field($wp->query_vars['lang']);
                            $default_lang  = $this->get_default_language();
                            if ($request_lang && $request_lang !== $default_lang) {
                                $translations = $this->get_post_translations($page_id);
                                if (isset($translations[$request_lang])) {
                                    $page_id = (int) $translations[$request_lang];
                                }
                            }

                            $wp->query_vars['page_id']   = $page_id;
                            $wp->query_vars[$matched_qv] = $matched_value;
                            unset($wp->query_vars['pagename']);
                        }
                    }
                }
            }
        }

        // AJAX requests (e.g. WooCommerce cart fragments / mini-cart) are sent to
        // an unprefixed URL, so no language is detected from the path above. Derive
        // it from the referring page (the page the request was made from), then a
        // language cookie, so the mini-cart, "View cart" link, product links and
        // prices render in the visitor's current language instead of the default.
        if (!$this->current_language) {
            $ajax_lang = $this->detect_ajax_language();
            if ($ajax_lang) {
                $this->current_language = $ajax_lang;
            }
        }

        if (!$this->current_language) {
            $this->current_language = $this->get_default_language();
        }
    }

    /**
     * Runtime fallback for prefixed custom-post-type singles that fell through to the
     * low-priority catch-all rule (^(lang)/(.+?)/?$ → pagename). When a public CPT's
     * own prefixed rewrite rule hasn't been flushed yet — common when the post type is
     * registered after our rules are built (JetEngine, SureCart, Pods, etc.) — a URL
     * like /nl/realisaties/my-project/ otherwise 404s. Here we detect that the matched
     * pagename is "{cpt-rewrite-slug}/{post-slug}" for a real CPT post and rewrite the
     * query to that single, so it resolves without waiting for a rewrite flush.
     *
     * @param WP $wp
     */
    private function maybe_resolve_cpt_from_catchall($wp) {
        if (empty($wp->query_vars['lang']) || empty($wp->query_vars['pagename'])) {
            return;
        }
        // Only act when the request hasn't already resolved to a post / CPT / page.
        if (!empty($wp->query_vars['post_type']) || !empty($wp->query_vars['name'])
            || !empty($wp->query_vars['page_id'])) {
            return;
        }

        $pagename = trim((string) $wp->query_vars['pagename'], '/');
        if ($pagename === '') {
            return;
        }

        // A real hierarchical page at this exact path wins — leave it alone.
        $page_at_path = get_page_by_path($pagename);
        if ($page_at_path && $page_at_path->post_type === 'page') {
            return;
        }

        foreach (get_post_types(['public' => true, '_builtin' => false], 'objects') as $cpt) {
            $cpt_slug = isset($cpt->rewrite['slug']) ? $cpt->rewrite['slug'] : $cpt->name;
            $cpt_slug = trim((string) $cpt_slug, '/');
            if ($cpt_slug === '') {
                continue;
            }

            // Archive: /{lang}/{cpt-slug}/
            if ($pagename === $cpt_slug) {
                if ($cpt->has_archive) {
                    unset($wp->query_vars['pagename']);
                    $wp->query_vars['post_type'] = $cpt->name;
                }
                return;
            }

            // Single: /{lang}/{cpt-slug}/{post-slug}/ (nested path for hierarchical CPTs).
            if (strpos($pagename, $cpt_slug . '/') === 0) {
                $rest = trim(substr($pagename, strlen($cpt_slug) + 1), '/');
                if ($rest === '') {
                    return;
                }
                $hierarchical = is_post_type_hierarchical($cpt->name);
                if ($hierarchical) {
                    $post_slug = $rest;
                } else {
                    $parts = explode('/', $rest);
                    $post_slug = end($parts);
                }

                // Only rewrite if a matching post actually exists (avoids hijacking
                // unrelated URLs that merely start with a CPT slug).
                $found = get_posts([
                    'post_type'        => $cpt->name,
                    'name'             => $post_slug,
                    'post_status'      => ['publish', 'draft', 'pending', 'private', 'future'],
                    'posts_per_page'   => 1,
                    'fields'           => 'ids',
                    'no_found_rows'    => true,
                    'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
                ]);
                if (!empty($found)) {
                    unset($wp->query_vars['pagename']);
                    $wp->query_vars['post_type'] = $cpt->name;
                    $wp->query_vars['name']      = $post_slug;
                }
                return;
            }
        }
    }

    /**
     * Extract a valid language code from the leading segment of a URL path.
     *
     * @param string $path
     * @return string|null
     */
    public function get_language_from_path($path) {
        $path = (string) $path;
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        if ($home_path && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }
        $segment = strtok(ltrim($path, '/'), '/');
        if ($segment && $this->language_exists($segment)) {
            return $segment;
        }
        return null;
    }

    /**
     * Determine the language for an AJAX request (which carries no URL prefix)
     * from the HTTP referer, falling back to the language cookie.
     *
     * @return string|null
     */
    public function detect_ajax_language() {
        // Memoize: this can be called from several render-time filters per request.
        static $memo = null;
        if ($memo !== null) {
            return $memo ?: null;
        }
        $memo = false;

        $is_ajax = (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('DOING_AJAX') && DOING_AJAX)
            || (defined('REST_REQUEST') && REST_REQUEST)   // Store API cart/checkout fragments
            || isset($_GET['wc-ajax'])
            || (isset($_REQUEST['action']) && $_REQUEST['action'] === 'jet_engine_ajax')
            || !empty($_REQUEST['jet_engine_action']);
        if (!$is_ajax) {
            return null;
        }

        // 1) First check: determine the language from the queried post/page ID
        // (common in Elementor AJAX, JetEngine lazy load, and JetSmartFilters requests).
        // Since REST API requests send payload as application/json, we check php://input as well.
        $json_data = null;
        if (!empty($_SERVER['CONTENT_TYPE']) && strpos(strtolower($_SERVER['CONTENT_TYPE']), 'application/json') !== false) {
            $raw_body = file_get_contents('php://input');
            if (!empty($raw_body)) {
                $json_data = json_decode($raw_body, true);
            }
        }

        $req_data = is_array($json_data) ? array_merge($_REQUEST, $json_data) : $_REQUEST;
        $target_post_id = 0;

        if (!empty($req_data['post_id'])) {
            $target_post_id = absint($req_data['post_id']);
        } elseif (!empty($req_data['post_ID'])) {
            $target_post_id = absint($req_data['post_ID']);
        } elseif (!empty($req_data['editor_post_id'])) {
            $target_post_id = absint($req_data['editor_post_id']);
        } elseif (!empty($req_data['page_settings']['post_id'])) {
            $target_post_id = absint($req_data['page_settings']['post_id']);
        }

        if (!$target_post_id && !empty($req_data['queried_id'])) {
            $queried_obj_data = explode('|', sanitize_text_field(wp_unslash($req_data['queried_id'])));
            if (!empty($queried_obj_data[0])) {
                $target_post_id = absint($queried_obj_data[0]);
            }
        } elseif (!$target_post_id && !empty($req_data['page_settings']['queried_id'])) {
            $queried_obj_data = explode('|', sanitize_text_field(wp_unslash($req_data['page_settings']['queried_id'])));
            if (!empty($queried_obj_data[0])) {
                $target_post_id = absint($queried_obj_data[0]);
            }
        }

        if ($target_post_id > 0 && get_post_status($target_post_id)) {
            $lang = $this->get_post_language($target_post_id);
            if ($lang) {
                $memo = $lang;
                return $lang;
            }
        }

        // 2) Referring page path (e.g. https://site/en/boutique/).
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $referer_url = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
            $referer_host = wp_parse_url($referer_url, PHP_URL_HOST);
            $current_host = wp_parse_url(home_url(), PHP_URL_HOST);
            
            if ($referer_host === $current_host) {
                $referer_path = wp_parse_url($referer_url, PHP_URL_PATH) ?: '';
                $lang = $this->get_language_from_path($referer_path);
                if ($lang) {
                    $memo = $lang;
                    return $lang;
                } else {
                    // Referer is on our site but has no language prefix -> must be default language!
                    $default = $this->get_default_language();
                    $memo = $default;
                    return $default;
                }
            }
        }

        // 2) Language cookie set on normal page loads.
        if (!empty($_COOKIE['aitrfoel_lang'])) {
            $cookie_lang = sanitize_text_field(wp_unslash($_COOKIE['aitrfoel_lang']));
            if ($this->language_exists($cookie_lang)) {
                $memo = $cookie_lang;
                return $cookie_lang;
            }
        }

        return null;
    }

    /**
     * Persist the resolved frontend language in a cookie so AJAX requests
     * (cart fragments, etc.) can recover it. Runs on normal frontend page loads.
     */
    public function maybe_set_language_cookie() {
        if (is_admin() || wp_doing_ajax() || headers_sent()) {
            return;
        }
        if (!$this->is_active()) {
            return;
        }
        $lang = $this->get_current_language();
        if (!$lang) {
            return;
        }
        if (isset($_COOKIE['aitrfoel_lang']) && $_COOKIE['aitrfoel_lang'] === $lang) {
            return; // Already set to the current language.
        }
        setcookie('aitrfoel_lang', $lang, time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN);
        $_COOKIE['aitrfoel_lang'] = $lang; // Reflect immediately within this request.
    }

    /**
     * Get the currently active frontend language
     * @return string
     */
    public function get_current_language() {
        if ($this->current_language) {
            return $this->current_language;
        }
        return $this->get_default_language();
    }
    
    /**
     * Redirect unprefixed default-language URLs to their prefixed version
     * when the aitrfoel_default_lang_prefix option is enabled.
     * E.g., /my-page/ → /fr/my-page/ if French is the default language.
     */
    /**
     * Decide whether the current (or given) request path must be excluded from
     * language routing — i.e. never prefixed and never redirected.
     *
     * Always excludes non-page endpoints (REST, admin-ajax, WooCommerce API,
     * webhooks, feeds, login/xmlrpc) plus any custom paths the site owner lists
     * under Settings → "Exclude paths from language routing". This prevents the
     * router from prepending a language prefix to plugin API/rewrite endpoints
     * (e.g. /wcsag-api/...), which would 404 the request.
     *
     * @param string|null $request_path Optional path; defaults to REQUEST_URI.
     * @return bool
     */
    public function is_path_excluded_from_routing($request_path = null) {
        // REST API requests.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if ($request_path === null) {
            $request_path = isset($_SERVER['REQUEST_URI'])
                ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
                : '/';
        }

        // Strip query string.
        $path = (string) $request_path;
        $qpos = strpos($path, '?');
        if ($qpos !== false) {
            $path = substr($path, 0, $qpos);
        }

        // Strip the site's home path (subdirectory installs) and normalize.
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        if ($home_path && $home_path !== '/' && strpos($path, $home_path) === 0) {
            $path = substr($path, strlen($home_path));
        }
        $path = '/' . ltrim($path, '/');

        // Built-in, always-excluded non-page endpoints.
        $builtin_prefixes = [
            '/wp-json',       // REST
            '/wp-admin',
            '/wp-login.php',
            '/xmlrpc.php',
            '/wc-api',        // WooCommerce legacy API / webhooks
            '/feed',
            '/sitemap',       // sitemaps and sitemap.xml
        ];
        foreach ($builtin_prefixes as $prefix) {
            if (strpos($path, $prefix) === 0) {
                return true;
            }
        }

        // User-defined excluded paths/tokens (one per line). A line such as
        // "wcsag-api" matches any path containing that segment.
        $custom = get_option('aitrfoel_routing_excluded_paths', '');
        if (!empty($custom)) {
            $lines = preg_split('/[\r\n]+/', (string) $custom);
            foreach ($lines as $line) {
                $token = trim($line, " \t/");
                if ($token !== '' && stripos($path, $token) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function maybe_redirect_default_lang_prefix() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        if (!get_option('aitrfoel_default_lang_prefix', false)) {
            return;
        }
        
        if (!$this->is_active()) {
            return;
        }

        // Never prefix/redirect non-page endpoints (REST, WooCommerce API,
        // webhooks, feeds) or user-excluded custom paths (e.g. plugin API roots).
        if ($this->is_path_excluded_from_routing()) {
            return;
        }

        $default_lang = $this->get_default_language();

        // If we already detected a language from URL prefix, no redirect needed
        if ($this->current_language && $this->current_language !== $default_lang) {
            return;
        }
        
        // Check if the URL already has a language prefix
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        $relative = $request_uri;
        if ($home_path && strpos($relative, $home_path) === 0) {
            $relative = substr($relative, strlen($home_path));
        }
        $relative = ltrim($relative, '/');
        
        // If already prefixed with default lang, no redirect
        if (strpos($relative, $default_lang . '/') === 0 || $relative === $default_lang) {
            return;
        }
        
        // Check if prefixed with any other language
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if ($lang['code'] !== $default_lang && (strpos($relative, $lang['code'] . '/') === 0 || $relative === $lang['code'])) {
                return; // Already has a different language prefix
            }
        }
        
        // This is an unprefixed URL that should be the default language — redirect
        $redirect_url = home_url('/' . $default_lang . '/' . $relative);
        wp_safe_redirect($redirect_url, 301);
        exit;
    }
    
    /**
     * Prevent WordPress canonical redirect from stripping the language prefix.
     * Without this, /en/ (front page) gets redirected to / by WordPress core,
     * causing a redirect loop with maybe_redirect_default_lang_prefix().
     *
     * @param string $redirect_url  The canonical redirect URL.
     * @param string $requested_url The original requested URL.
     * @return string|false
     */
    public function filter_redirect_canonical($redirect_url, $requested_url) {
        if (!$this->is_active() || !$this->current_language) {
            return $redirect_url;
        }
        
        // If the current URL has a language prefix, don't let WP strip it
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $home_path = wp_parse_url(home_url(), PHP_URL_PATH) ?: '';
        $relative = $request_uri;
        if ($home_path && strpos($relative, $home_path) === 0) {
            $relative = substr($relative, strlen($home_path));
        }
        $relative = ltrim($relative, '/');
        
        $languages = $this->get_stored_languages();
        foreach ($languages as $lang) {
            if (strpos($relative, $lang['code'] . '/') === 0 || $relative === $lang['code']) {
                // URL has a valid language prefix — block canonical redirect
                return false;
            }
        }
        
        return $redirect_url;
    }
    
    /**
     * Filter WordPress locale based on the current frontend language.
     * This makes WooCommerce, other plugins, and WordPress core
     * output their UI strings in the correct language.
     *
     * @param string $locale Current WordPress locale
     * @return string Modified locale
     */
    public function filter_locale($locale) {
        // current_language is only set on parse_request, but get_locale() is called
        // earlier (e.g. when WooCommerce/theme load their translation files on init).
        // Detect the language straight from the URL here so the correct .mo files are
        // loaded from the start — otherwise WooCommerce UI strings (cart, checkout,
        // "View cart", "Subtotal", etc.) stay in the default language.
        $lang = $this->current_language ?: $this->detect_language_early();
        if (!$lang) {
            return $locale;
        }

        $default_lang = $this->get_default_language();
        if ($lang === $default_lang) {
            return $locale;
        }

        $wp_locale = $this->get_wp_locale($lang);
        return $wp_locale ?: $locale;
    }

    /**
     * On `init`, force WordPress (and therefore WooCommerce / the theme) to the
     * detected frontend language via switch_to_locale(), which reloads any
     * translations that were already loaded in the default language. This fixes
     * WooCommerce UI strings (cart, checkout, mini-cart labels) staying in the
     * source language on translated pages.
     */
    public function maybe_switch_locale_early() {
        if (is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax()) && !isset($_GET['wc-ajax'])) {
            return;
        }
        $lang = $this->detect_language_early();
        if (!$lang || $lang === $this->get_default_language()) {
            return;
        }
        $wp_locale = $this->get_wp_locale($lang);
        if ($wp_locale && function_exists('switch_to_locale')) {
            // Always switch (even if the locale filter already reports this locale)
            // so any translations pre-loaded in the default language are reloaded.
            switch_to_locale($wp_locale);
        }
    }

    /**
     * Resolve the frontend language from the request URL (and referer/cookie for
     * AJAX) before parse_request runs. Used by the locale filter so translation
     * files load in the correct language from the earliest hooks. Memoized.
     *
     * @return string|null
     */
    private function detect_language_early() {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved ?: null;
        }
        $resolved = false;

        if (!$this->is_active()) {
            return null;
        }
        // Never override the wp-admin UI locale (but do allow frontend AJAX).
        $is_frontend_ajax = (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('REST_REQUEST') && REST_REQUEST)
            || isset($_GET['wc-ajax']);
        if (is_admin() && !$is_frontend_ajax) {
            return null;
        }

        $uri = isset($_SERVER['REQUEST_URI'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']))
            : '';
        $q = strpos($uri, '?');
        if ($q !== false) {
            $uri = substr($uri, 0, $q);
        }

        $lang = $this->get_language_from_path($uri);
        if (!$lang) {
            $lang = $this->detect_ajax_language();
        }
        if ($lang) {
            $resolved = $lang;
            return $lang;
        }
        return null;
    }

    /**
     * Force the HTML <html lang=""> attribute to match the current frontend language.
     * Runs at priority 999 so it overrides any theme or SEO plugin (e.g. Rank Math)
     * that may have set a different lang code based on the site's default locale.
     *
     * @param string $attributes Existing language_attributes string.
     * @return string Modified attributes with correct lang value.
     */
    public function filter_language_attributes($attributes) {
        if (!$this->current_language) {
            return $attributes;
        }

        $wp_locale = $this->get_wp_locale($this->current_language);
        // Convert locale (en_US) to BCP-47 (en-US), fall back to bare code (en)
        $lang = $wp_locale ? str_replace('_', '-', $wp_locale) : $this->current_language;

        // Replace existing lang="..." if present, otherwise append it
        if (strpos($attributes, 'lang=') !== false) {
            $attributes = preg_replace('/lang="[^"]*"/', 'lang="' . esc_attr($lang) . '"', $attributes);
        } else {
            $attributes .= ' lang="' . esc_attr($lang) . '"';
        }

        return $attributes;
    }
    
    /**
     * Map a short language code to WordPress locale code.
     * E.g. 'cs' → 'cs_CZ', 'de' → 'de_DE', 'ru' → 'ru_RU'
     *
     * @param string $lang_code Short language code
     * @return string|null WordPress locale or null if unknown
     */
    private function get_wp_locale($lang_code) {
        $map = [
            'af' => 'af',       'ar' => 'ar',       'az' => 'az',
            'be' => 'bel',      'bg' => 'bg_BG',    'bn' => 'bn_BD',
            'bs' => 'bs_BA',    'ca' => 'ca',       'cs' => 'cs_CZ',
            'cy' => 'cy',       'da' => 'da_DK',    'de' => 'de_DE',
            'el' => 'el',       'en' => 'en_US',    'es' => 'es_ES',
            'et' => 'et',       'eu' => 'eu',       'fa' => 'fa_IR',
            'fi' => 'fi',       'fr' => 'fr_FR',    'ga' => 'ga',
            'gl' => 'gl_ES',    'he' => 'he_IL',    'hi' => 'hi_IN',
            'hr' => 'hr',       'hu' => 'hu_HU',    'hy' => 'hy',
            'id' => 'id_ID',    'is' => 'is_IS',    'it' => 'it_IT',
            'ja' => 'ja',       'ka' => 'ka_GE',    'kk' => 'kk',
            'km' => 'km',       'ko' => 'ko_KR',    'lo' => 'lo',
            'lt' => 'lt_LT',    'lv' => 'lv',       'mk' => 'mk_MK',
            'mn' => 'mn',       'ms' => 'ms_MY',    'my' => 'my_MM',
            'nb' => 'nb_NO',    'ne' => 'ne_NP',    'nl' => 'nl_NL',
            'nn' => 'nn_NO',    'pl' => 'pl_PL',    'pt' => 'pt_PT',
            'ro' => 'ro_RO',    'ru' => 'ru_RU',    'si' => 'si_LK',
            'sk' => 'sk_SK',    'sl' => 'sl_SI',    'sq' => 'sq',
            'sr' => 'sr_RS',    'sv' => 'sv_SE',    'sw' => 'sw',
            'ta' => 'ta_IN',    'te' => 'te',       'th' => 'th',
            'tl' => 'tl',       'tr' => 'tr_TR',    'uk' => 'uk',
            'ur' => 'ur',       'uz' => 'uz_UZ',    'vi' => 'vi',
            'zh' => 'zh_CN',    'an' => 'an',       'as' => 'as',
            'lb' => 'lb_LU',
        ];
        
        return $map[$lang_code] ?? null;
    }
    
    /**
     * Filter WordPress/WooCommerce page ID options to return translated page ID.
     * Ensures is_front_page(), is_shop(), is_cart(), etc. work correctly
     * when viewing a translated version of these pages.
     *
     * @param mixed  $pre_value Pre-filter value (false = not filtered yet).
     * @param string $option    Option name.
     * @return mixed Page ID of translated version, or $pre_value to use default.
     */
    public function filter_translated_page_option($pre_value, $option) {
        if (!$this->is_active()) {
            return $pre_value;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        if (!$current_lang || $current_lang === $default_lang) {
            return $pre_value;
        }

        // Prevent infinite loop: temporarily remove this filter while reading the option
        static $filtering = [];
        if (isset($filtering[$option])) {
            return $pre_value;
        }
        $filtering[$option] = true;

        $original_page_id = (int) get_option($option);
        unset($filtering[$option]);

        if ($original_page_id <= 0) {
            return $pre_value;
        }

        $translations = $this->get_post_translations($original_page_id);
        if (isset($translations[$current_lang])) {
            $translated_id = (int) $translations[$current_lang];
            if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
                return $translated_id;
            }
        }

        return $pre_value;
    }

    /**
     * Filter home_url to include current language prefix.
     * Ensures logo links, home links etc. point to the correct language.
     *
     * @param string $url
     * @param string $path
     * @param string|null $orig_scheme
     * @param int|null $blog_id
     * @return string
     */
    public function filter_home_url($url, $path, $orig_scheme = null, $blog_id = null) {
        if (!$this->is_active()) {
            return $url;
        }
        
        // Only prefix the root home URL (empty path or just "/").
        // Do NOT prefix when WordPress is building post/page permalinks
        // (e.g. home_url('/my-page/')) — our permalink filters handle those.
        $trimmed_path = trim($path, '/');
        if ($trimmed_path !== '') {
            return $url;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // Don't prefix for default language
        if (!$current_lang || $current_lang === $default_lang) {
            return $url;
        }
        
        // Only for pretty permalinks
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $current_lang, $url);
        }
        
        // Use raw home option to avoid recursion
        $raw_home = trailingslashit(get_option('home'));

        return $raw_home . $current_lang . '/';
    }

    /**
     * Ensure WooCommerce's AJAX endpoint URL (cart fragments, add-to-cart, etc.)
     * includes the current language prefix, so the request is detected as the
     * current language directly from the URL — fixing the mini-cart rendering in
     * the default language.
     *
     * @param string $url
     * @return string
     */
    public function filter_wc_ajax_endpoint($url) {
        if (!$this->is_active() || !is_string($url) || $url === '') {
            return $url;
        }
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $url;
        }
        // Pretty permalinks only; plain permalinks already carry ?lang= via home_url.
        if (empty(get_option('permalink_structure', ''))) {
            return $url;
        }

        // WooCommerce builds this endpoint as a RELATIVE URL (e.g.
        // "/?wc-ajax=%%endpoint%%") via home_url('/', 'relative'), but it can also
        // be absolute. Handle both by working on the path component.
        $parsed = wp_parse_url($url);
        if ($parsed === false) {
            return $url;
        }
        $path  = isset($parsed['path']) ? $parsed['path'] : '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        // Strip the site's base path (subdirectory installs) before checking/adding the prefix.
        $base_path = wp_parse_url(get_option('home'), PHP_URL_PATH) ?: '';
        $base_path = $base_path === '/' ? '' : rtrim($base_path, '/');
        $rel = $path;
        if ($base_path !== '' && strpos($rel, $base_path) === 0) {
            $rel = substr($rel, strlen($base_path));
        }
        $rel = '/' . ltrim($rel, '/');

        // Already language-prefixed? Leave it.
        $first_segment = strtok(ltrim($rel, '/'), '/');
        $lang_codes = array_column($this->get_stored_languages(), 'code');
        if ($first_segment && in_array($first_segment, $lang_codes, true)) {
            return $url;
        }

        $new_path = $base_path . '/' . $current_lang . $rel;

        // Preserve absolute form if the original was absolute.
        if (!empty($parsed['host'])) {
            $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] . '://' : '//';
            $port   = !empty($parsed['port']) ? ':' . $parsed['port'] : '';
            return $scheme . $parsed['host'] . $port . $new_path . $query;
        }
        return $new_path . $query;
    }

    /**
     * Filter permalink to add language prefix (for posts)
     * @param string $permalink
     * @param WP_Post|int $post
     * @return string
     */
    public function filter_permalink($permalink, $post) {
        if (!$this->is_active()) {
            return $permalink;
        }
        
        $post_id = is_object($post) ? $post->ID : $post;
        $lang = $this->get_post_language($post_id);
        $default_lang = $this->get_default_language();
        
        if (!$lang) {
            return $permalink;
        }
        
        if ($lang === $default_lang && !get_option('aitrfoel_default_lang_prefix', false)) {
            return $permalink;
        }
        
        // Plain permalinks: use ?lang= query parameter instead of URL prefix
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $lang, $permalink);
        }
        
        // Use raw home URL to avoid double-prefix from filter_home_url
        $home_url = trailingslashit(get_option('home'));
        if (strpos($permalink, $home_url) === 0) {
            // Don't double-add language prefix
            $relative = substr($permalink, strlen($home_url));
            $lang_codes = array_column($this->get_stored_languages(), 'code');
            foreach ($lang_codes as $code) {
                if (strpos($relative, $code . '/') === 0) {
                    return $permalink; // Already prefixed
                }
            }
            $permalink = $home_url . $lang . '/' . ltrim($relative, '/');
        }
        
        return $permalink;
    }
    
    /**
     * Filter page permalink to add language prefix
     * @param string $permalink
     * @param int $page_id
     * @return string
     */
    public function filter_page_permalink($permalink, $page_id) {
        if (!$this->is_active()) {
            return $permalink;
        }
        
        $lang = $this->get_post_language($page_id);
        $default_lang = $this->get_default_language();
        
        if (!$lang) {
            return $permalink;
        }
        
        // Plain permalinks: use ?lang= query parameter instead of URL prefix
        if (empty(get_option('permalink_structure', ''))) {
            return add_query_arg('lang', $lang, $permalink);
        }
        
        // If this page is a static front page or a translation of the static front page
        $front_page_id = (int) get_option('page_on_front');
        if ($front_page_id && get_option('show_on_front') === 'page') {
            $translations = $this->get_post_translations($front_page_id);
            $all_front_ids = array_map('intval', array_values($translations));
            $all_front_ids[] = $front_page_id;
            if (in_array((int) $page_id, $all_front_ids, true)) {
                $raw_home = trailingslashit(get_option('home'));
                if ($lang === $default_lang) {
                    return get_option('aitrfoel_default_lang_prefix', false) ? $raw_home . $default_lang . '/' : $raw_home;
                }
                return $raw_home . $lang . '/';
            }
        }

        if ($lang === $default_lang && !get_option('aitrfoel_default_lang_prefix', false)) {
            return $permalink;
        }
        
        // Use raw home URL to avoid double-prefix from filter_home_url
        $home_url = trailingslashit(get_option('home'));
        if (strpos($permalink, $home_url) === 0) {
            $relative = substr($permalink, strlen($home_url));
            $lang_codes = array_column($this->get_stored_languages(), 'code');
            foreach ($lang_codes as $code) {
                if (strpos($relative, $code . '/') === 0) {
                    return $permalink;
                }
            }
            $permalink = $home_url . $lang . '/' . ltrim($relative, '/');
        }
        
        return $permalink;
    }
    
    /**
     * Convert a translated Shop page resolved as a singular page into a clean product archive.
     *
     * When WooCommerce resolves /en/shop/ it uses the product post-type archive (no page_id
     * constraint). But a translated shop page (e.g. /ru/магазин/) resolves as a normal
     * singular page (is_page=true, page_id=9673). WooCommerce never strips that singular
     * constraint, so the product query ends up as:
     *   WHERE wp_posts.ID = '9673' AND post_type = 'product'
     * which always returns 0 rows.
     *
     * Fix: at priority 5 (before WooCommerce's product_query at priority 10), detect this
     * case and convert the query into a proper product archive — clearing is_page/is_singular
     * and the page_id, and setting is_post_type_archive so WooCommerce builds the correct query.
     *
     * @param WP_Query $query
     */
    public function maybe_convert_shop_page_query($query) {
        $active  = $this->is_active();
        $is_main = $query->is_main_query();
        $is_page = $query->is_page();
        $qid     = (int) $query->get('page_id') ?: (int) $query->queried_object_id;
        $wc_ok   = function_exists('wc_get_page_id') && class_exists('WooCommerce');
        $shop_id = $wc_ok ? (int) wc_get_page_id('shop') : 0;

        if (!$active || is_admin() || !$is_main) {
            return;
        }

        if (!$wc_ok) {
            return;
        }

        // Only act when the query resolved as a singular page.
        if (!$is_page) {
            return;
        }

        $queried_id = $qid;
        if (!$queried_id) {
            return;
        }

        // Check whether this page is the (translated) WooCommerce shop page.
        // wc_get_page_id('shop') already returns the translated ID because our
        // pre_option_woocommerce_shop_page_id filter is in place.
        $shop_page_id = $shop_id;
        if (!$shop_page_id || $queried_id !== $shop_page_id) {
            return;
        }

        // Convert into a product post-type archive: clear all singular flags,
        // set the archive flags, remove the page_id constraint.
        // IMPORTANT: use '' (empty string) not 0 — WordPress uses '' !== $q['page_id']
        // with strict inequality, so integer 0 would still add AND ID = '0' to the SQL.
        $query->is_page             = false;
        $query->is_singular         = false;
        $query->is_archive          = true;
        $query->is_post_type_archive = true;
        $query->set('post_type', 'product');
        $query->set('page_id', '');
        $query->set('p', '');
        $query->set('pagename', '');

        // Preserve WooCommerce's expected per-page count.
        $per_page = (int) get_option('posts_per_page', 12);
        if (function_exists('wc_get_default_products_per_row')) {
            $per_page = max($per_page, (int) get_option('woocommerce_catalog_columns', 4) * (int) get_option('woocommerce_catalog_rows', 4));
        }
        $query->set('posts_per_page', $per_page);
    }

    /**
     * Filter frontend queries to show only current language posts
     * @param WP_Query $query
     */
    public function filter_frontend_queries($query) {
        if (!$this->is_active() || is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $current_lang = $this->get_current_language();
        if (!$current_lang) {
            return;
        }
        
        // Filter archives, search, blog index, taxonomy archives
        if ($query->is_archive() || $query->is_home() || $query->is_search() || $query->is_tax()) {
            $default_lang = $this->get_default_language();
            $terms = [$current_lang];
            
            // WooCommerce product fallback: include default language products
            // so untranslated products still appear on shop/category pages
            $is_product_fallback = false;
            if ($current_lang !== $default_lang && class_exists('WooCommerce') && get_option('aitrfoel_woo_product_fallback', true)) {
                $queried_post_type = $query->get('post_type');
                $is_product = ($queried_post_type === 'product')
                    || (is_array($queried_post_type) && in_array('product', $queried_post_type, true));
                if ($is_product) {
                    $terms[] = $default_lang;
                    $is_product_fallback = true;
                }
            }
            
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $terms,
            ];
            $query->set('tax_query', $tax_query);
            
            // Deduplicate: remove default-lang products that have a current-lang translation
            if ($is_product_fallback) {
                add_filter('the_posts', [$this, 'deduplicate_fallback_products'], 10, 2);
            }
        }
        
        // For single posts/pages: if language prefix is in URL but post is in a different language,
        // WordPress will handle the 404 naturally. We just ensure the current language is set.
    }

    /**
     * Remove default-language products from results when a current-language translation exists.
     * Runs once as a the_posts filter after WooCommerce product fallback query.
     *
     * @param array    $posts Array of WP_Post objects.
     * @param WP_Query $query The query object.
     * @return array
     */
    public function deduplicate_fallback_products($posts, $query) {
        remove_filter('the_posts', [$this, 'deduplicate_fallback_products'], 10);

        if (empty($posts)) {
            return $posts;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        // Collect IDs of default-lang source posts that have a translated version in this result set
        $translated_source_ids = [];
        foreach ($posts as $post) {
            $lang = $this->get_post_language($post->ID);
            if ($lang === $current_lang) {
                // This is a translated product — find its source (default lang) post
                $translations = $this->get_post_translations($post->ID);
                if (isset($translations[$default_lang])) {
                    $translated_source_ids[] = (int) $translations[$default_lang];
                }
            }
        }

        // Remove default-lang products whose translation is already in the results
        if (!empty($translated_source_ids)) {
            $posts = array_values(array_filter($posts, function($post) use ($translated_source_ids, $default_lang) {
                $lang = $this->get_post_language($post->ID);
                return !($lang === $default_lang && in_array((int) $post->ID, $translated_source_ids, true));
            }));
        }

        return $posts;
    }

    /**
     * Filter a single term to show its translated name on the frontend.
     * Reads from term meta `_aitrfoel_name_{lang}` set during translation.
     *
     * @param WP_Term $term     Term object.
     * @param string  $taxonomy Taxonomy slug.
     * @return WP_Term
     */
    public function filter_term_name($term, $taxonomy) {
        if (!is_object($term) || !isset($term->term_id)) {
            return $term;
        }
        
        // Skip our own language taxonomy
        if ($taxonomy === self::TAXONOMY) {
            return $term;
        }
        
        $current_lang = $this->get_current_language();

        // Translate term names for any resolved language with a stored translation —
        // including the default, since a switched default still needs its terms shown
        // in the new language. When no _aitrfoel_name_{lang} meta exists (e.g. the
        // original language), the term is left unchanged, so this is safe everywhere.
        if (!$current_lang) {
            return $term;
        }

        $translated_name = get_term_meta($term->term_id, '_aitrfoel_name_' . $current_lang, true);
        if (!empty($translated_name)) {
            $term->name = $translated_name;
        }
        
        return $term;
    }
    
    /**
     * Filter an array of terms to show translated names on the frontend.
     *
     * @param array         $terms      Array of found terms.
     * @param array|null    $taxonomies Array of taxonomy slugs (may be null).
     * @param array         $query_vars Query variables.
     * @param WP_Term_Query $term_query Term query object.
     * @return array
     */
    public function filter_terms_names($terms, $taxonomies, $query_vars, $term_query = null) {
        if (empty($terms)) {
            return $terms;
        }
        
        $current_lang = $this->get_current_language();

        // See filter_term_name: run for any resolved language; terms without a stored
        // translation for that language are left unchanged.
        if (!$current_lang) {
            return $terms;
        }

        foreach ($terms as &$term) {
            if (!is_object($term) || !isset($term->term_id)) {
                continue;
            }
            
            // Skip our language taxonomy
            if (isset($term->taxonomy) && $term->taxonomy === self::TAXONOMY) {
                continue;
            }
            
            $translated_name = get_term_meta($term->term_id, '_aitrfoel_name_' . $current_lang, true);
            if (!empty($translated_name)) {
                $term->name = $translated_name;
            }
        }
        unset($term);

        return $terms;
    }

    /**
     * Translate JetSmartFilters labels at render time.
     *
     * Hooked on `jet-smart-filters/filter-instance/args` — the single point where
     * every option-based filter (radio, checkboxes, select, color-image, …) exposes
     * its fully-built args, including the prepended "Show all" option. This means one
     * hook covers:
     *   - taxonomy option labels (resolved from `_aitrfoel_name_{lang}` term meta, so
     *     it works even when JetSmartFilters' indexer caches the default-language
     *     names and bypasses the get_terms() filter),
     *   - the admin-entered "Show all" label and other non-term option labels
     *     (resolved through the custom-string map), and
     *   - helper strings like the dropdown placeholder / more / less text.
     *
     * Runs on the front end and in JetSmartFilters AJAX re-renders. Language is
     * resolved locally (not via $this->st_lang, which is only set on the `wp` action
     * that admin-ajax does not fire).
     *
     * @param array  $args     The filter instance args (passed by reference value).
     * @param object $instance The Jet_Smart_Filters_Filter_Instance (unused).
     * @return array
     */
    /**
     * Resolve a single admin string through the custom-string map for a given
     * language. Tries the raw value and an entity-decoded copy (some plugins
     * htmlspecialchars()-encode labels before render), so the lookup matches the
     * stored source. Returns the input unchanged when there's no override.
     *
     * @param string      $text
     * @param string      $lang Target language code.
     * @param array|null  $map  Optional pre-fetched map (avoids repeated lookups).
     * @return string
     */
    private function map_translate($text, $lang, $map = null) {
        if (!is_string($text) || $text === '' || !$lang) {
            return $text;
        }
        if ($map === null) {
            $map = $this->get_string_translation_map();
        }
        foreach ([trim($text), trim(html_entity_decode($text, ENT_QUOTES))] as $key) {
            if ($key !== '' && isset($map[$key][$lang]) && $map[$key][$lang] !== '') {
                return $map[$key][$lang];
            }
        }
        return $text;
    }

    /**
     * Translate JetFormBuilder field blocks before they render.
     *
     * Hooked on WordPress core's `render_block_data`, which fires for every block
     * on the page. We bail immediately for anything outside the `jet-forms/*`
     * namespace, then translate the field's user-facing string attributes
     * (label, description, placeholder, option labels, add-more / page-nav button
     * text) through the custom-string map. Field attribute keys are stable and
     * confirmed from JetFormBuilder's own form patterns.
     *
     * @param array $parsed_block A single parsed block array.
     * @return array
     */
    public function translate_jfb_block_attrs($parsed_block) {
        if (empty($parsed_block['blockName']) || strpos($parsed_block['blockName'], 'jet-forms/') !== 0) {
            return $parsed_block;
        }
        if (empty($parsed_block['attrs']) || !is_array($parsed_block['attrs']) || !$this->is_active()) {
            return $parsed_block;
        }

        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $parsed_block;
        }

        $map = $this->get_string_translation_map();
        $a   = &$parsed_block['attrs'];

        foreach (['label', 'desc', 'placeholder', 'add_more_label', 'prev_label', 'next_label'] as $k) {
            if (!empty($a[$k]) && is_string($a[$k])) {
                $a[$k] = $this->map_translate($a[$k], $lang, $map);
            }
        }

        if (!empty($a['field_options']) && is_array($a['field_options'])) {
            foreach ($a['field_options'] as &$opt) {
                if (is_array($opt) && !empty($opt['label']) && is_string($opt['label'])) {
                    $opt['label'] = $this->map_translate($opt['label'], $lang, $map);
                }
            }
            unset($opt);
        }
        unset($a);

        return $parsed_block;
    }

    /**
     * Translate a WPForms form before render. Hooked on `wpforms_frontend_form_data`.
     * Covers field labels/descriptions/placeholders, choice labels, the submit button
     * text, and confirmation messages. Strings resolve through the custom-string map.
     *
     * @param array $form_data
     * @return array
     */
    public function translate_wpforms_form_data($form_data) {
        if (!is_array($form_data) || !$this->is_active()) {
            return $form_data;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $form_data;
        }
        $map = $this->get_string_translation_map();

        if (!empty($form_data['fields']) && is_array($form_data['fields'])) {
            foreach ($form_data['fields'] as &$field) {
                if (!is_array($field)) {
                    continue;
                }
                foreach (['label', 'description', 'placeholder'] as $k) {
                    if (!empty($field[$k]) && is_string($field[$k])) {
                        $field[$k] = $this->map_translate($field[$k], $lang, $map);
                    }
                }
                if (!empty($field['choices']) && is_array($field['choices'])) {
                    foreach ($field['choices'] as &$choice) {
                        if (is_array($choice) && !empty($choice['label']) && is_string($choice['label'])) {
                            $choice['label'] = $this->map_translate($choice['label'], $lang, $map);
                        }
                    }
                    unset($choice);
                }
            }
            unset($field);
        }

        if (!empty($form_data['settings']) && is_array($form_data['settings'])) {
            $s = &$form_data['settings'];
            foreach (['submit_text', 'submit_text_processing'] as $k) {
                if (!empty($s[$k]) && is_string($s[$k])) {
                    $s[$k] = $this->map_translate($s[$k], $lang, $map);
                }
            }
            if (!empty($s['confirmations']) && is_array($s['confirmations'])) {
                foreach ($s['confirmations'] as &$conf) {
                    if (is_array($conf) && !empty($conf['message']) && is_string($conf['message'])) {
                        $conf['message'] = $this->map_translate($conf['message'], $lang, $map);
                    }
                }
                unset($conf);
            }
            unset($s);
        }

        return $form_data;
    }

    /**
     * Translate a Gravity Forms form before render. Hooked on `gform_pre_render`.
     * GF passes an array whose `fields` are GF_Field objects (mutated by reference),
     * with choice labels under the `text` key. Also covers the submit button text and
     * confirmation messages.
     *
     * @param array $form
     * @return array
     */
    public function translate_gform_form($form) {
        if (!is_array($form) || !$this->is_active()) {
            return $form;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $form;
        }
        $map = $this->get_string_translation_map();

        if (!empty($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if (!is_object($field)) {
                    continue;
                }
                foreach (['label', 'description', 'placeholder'] as $k) {
                    if (!empty($field->$k) && is_string($field->$k)) {
                        $field->$k = $this->map_translate($field->$k, $lang, $map);
                    }
                }
                if (!empty($field->choices) && is_array($field->choices)) {
                    $choices = $field->choices;
                    foreach ($choices as &$choice) {
                        if (is_array($choice) && !empty($choice['text']) && is_string($choice['text'])) {
                            $choice['text'] = $this->map_translate($choice['text'], $lang, $map);
                        }
                    }
                    unset($choice);
                    $field->choices = $choices;
                }
            }
        }

        if (!empty($form['button']['text']) && is_string($form['button']['text'])) {
            $form['button']['text'] = $this->map_translate($form['button']['text'], $lang, $map);
        }

        if (!empty($form['confirmations']) && is_array($form['confirmations'])) {
            foreach ($form['confirmations'] as &$conf) {
                if (is_array($conf) && !empty($conf['message']) && is_string($conf['message'])) {
                    $conf['message'] = $this->map_translate($conf['message'], $lang, $map);
                }
            }
            unset($conf);
        }

        return $form;
    }

    /**
     * Translate a Contact Form 7 form's rendered HTML. Hooked on `wpcf7_form_elements`.
     * CF7 forms are free-form markup, so we translate visible text nodes only (never
     * tag names or attributes) via the custom-string map.
     *
     * @param string $html
     * @return string
     */
    public function translate_cf7_form_html($html) {
        if (!is_string($html) || $html === '' || !$this->is_active()) {
            return $html;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $html;
        }
        $map = $this->get_string_translation_map();
        if (empty($map)) {
            return $html;
        }
        return $this->translate_html_text_nodes($html, $lang, $map);
    }

    /**
     * Translate a Contact Form 7 response message (validation / success). Hooked on
     * `wpcf7_display_message`.
     *
     * @param string $message
     * @param string $status
     * @return string
     */
    public function translate_cf7_display_message($message, $status = '') {
        if (!is_string($message) || $message === '' || !$this->is_active()) {
            return $message;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $message;
        }
        return $this->map_translate($message, $lang, $this->get_string_translation_map());
    }

    /**
     * Translate the visible text nodes of an HTML fragment through the custom-string
     * map, leaving tags and attributes untouched. Used for free-form markup (CF7).
     *
     * @param string $html
     * @param string $lang
     * @param array  $map
     * @return string
     */
    private function translate_html_text_nodes($html, $lang, $map) {
        // Plain text (no markup): translate directly.
        if (strpos($html, '<') === false) {
            return $this->map_translate($html, $lang, $map);
        }
        if (!class_exists('DOMDocument')) {
            return $html;
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument();
        // Force UTF-8 and wrap in a marker element so we can extract just the fragment.
        $wrapped = '<?xml encoding="utf-8"?><div data-aitrfoel-cf7="1">' . $html . '</div>';
        $loaded  = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return $html;
        }

        $xpath   = new DOMXPath($doc);
        $changed = false;
        foreach ($xpath->query('//text()') as $node) {
            $text = $node->nodeValue;
            if (trim($text) === '') {
                continue;
            }
            $parent = $node->parentNode ? strtolower($node->parentNode->nodeName) : '';
            if ($parent === 'script' || $parent === 'style') {
                continue;
            }
            $trimmed = trim($text);
            $tr      = $this->map_translate($trimmed, $lang, $map);
            if ($tr !== $trimmed) {
                // Preserve the node's surrounding whitespace.
                $node->nodeValue = str_replace($trimmed, $tr, $text);
                $changed = true;
            }
        }

        if (!$changed) {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            return $html;
        }

        $wrap = $xpath->query('//div[@data-aitrfoel-cf7]')->item(0);
        $out  = '';
        if ($wrap) {
            foreach ($wrap->childNodes as $child) {
                $out .= $doc->saveHTML($child);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $out !== '' ? $out : $html;
    }

    /**
     * Translate a SureCart product's name / description at the model level. Hooked on
     * `surecart/product/get_attribute`, which fires whenever any block or template
     * reads a product attribute, so a single hook covers the product page, collection
     * grids, checkout line items, etc.
     *
     * SureCart products are translated like WooCommerce products: into a linked post
     * copy whose post_title holds the translated name and post_excerpt the translated
     * description (created and reviewed through the dashboard's Products tab). So this
     * returns the current-language copy's field rather than a flat string override.
     * The base Model guards against re-entrancy; we read only the passed value and the
     * product id to stay loop-safe.
     *
     * @param mixed  $value   The attribute value.
     * @param string $key     The attribute name.
     * @param object $product The product model.
     * @return mixed
     */
    public function translate_surecart_product_attribute($value, $key, $product = null) {
        if (!is_string($value) || $value === '' || !$this->is_active()) {
            return $value;
        }
        if ($key !== 'name' && $key !== 'description') {
            return $value;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $value;
        }

        $sc_id  = is_object($product) ? ($product->id ?? '') : '';
        $fields = $this->get_surecart_translated_fields($sc_id, $lang);
        if ($fields === null) {
            return $value;
        }
        if ($key === 'name' && $fields['name'] !== '') {
            return $fields['name'];
        }
        if ($key === 'description' && $fields['description'] !== '') {
            return $fields['description'];
        }
        return $value;
    }

    /**
     * Resolve the current-language translated copy of a SureCart product and return
     * its name (post_title) and description (post_excerpt). Mapped by the product's
     * cloud id (the `sc_id` post meta SureCart's sync stores on every product post)
     * and cached per request. Returns null when no translation exists.
     *
     * @param string $sc_id SureCart product id.
     * @param string $lang  Target language code.
     * @return array|null   ['name' => string, 'description' => string] or null.
     */
    private function get_surecart_translated_fields($sc_id, $lang) {
        if (!is_string($sc_id) || $sc_id === '') {
            return null;
        }
        $ck = $sc_id . '|' . $lang;
        if (array_key_exists($ck, $this->sc_translated_fields_cache)) {
            return $this->sc_translated_fields_cache[$ck];
        }

        $result = null;
        $ids    = get_posts([
            'post_type'        => 'sc_product',
            'post_status'      => 'any',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'meta_key'         => 'sc_id',
            'meta_value'       => $sc_id,
            'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- intentional: Polylang/WPML query filters would hide posts in other languages, which the translator must see
        ]);
        if (!empty($ids)) {
            $translations = $this->get_post_translations($ids[0]);
            if (!empty($translations[$lang])) {
                $tp = get_post($translations[$lang]);
                if ($tp) {
                    $result = [
                        'name'        => (string) $tp->post_title,
                        'description' => (string) $tp->post_excerpt,
                    ];
                }
            }
        }

        $this->sc_translated_fields_cache[$ck] = $result;
        return $result;
    }

    public function translate_jsf_filter_args($args, $instance = null) {
        if (!is_array($args) || empty($args['options']) || !is_array($args['options']) || !$this->is_active()) {
            return $args;
        }

        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return $args;
        }

        $map      = $this->get_string_translation_map();
        $taxonomy = (isset($args['query_type']) && $args['query_type'] === 'tax_query' && !empty($args['query_var']))
            ? $args['query_var']
            : '';

        // Resolve an admin string through the custom-string map.
        $tr_string = function ($text) use ($map, $lang) {
            return $this->map_translate($text, $lang, $map);
        };

        // Prefer the translated term name from term meta; fall back to the string map.
        $tr_term = function ($term_id, $fallback) use ($taxonomy, $lang, $tr_string) {
            if ($taxonomy && is_numeric($term_id)) {
                $name = get_term_meta((int) $term_id, '_aitrfoel_name_' . $lang, true);
                if (!empty($name)) {
                    return $name;
                }
            }
            return $tr_string($fallback);
        };

        foreach ($args['options'] as $key => &$opt) {
            if ($key === 'all') {
                // The "Show all" entry: a string (flat) or {term_id:'all', name} object
                // (hierarchical). Always an admin label, never a term.
                if (is_object($opt) && isset($opt->name)) {
                    $opt->name = $tr_string($opt->name);
                } elseif (is_string($opt)) {
                    $opt = $tr_string($opt);
                }
                continue;
            }

            if (is_string($opt)) {
                $opt = $tr_term($key, $opt);
            } elseif (is_array($opt) && isset($opt['label']) && is_string($opt['label'])) {
                $val           = isset($opt['value']) ? $opt['value'] : $key;
                $opt['label']  = $tr_term($val, $opt['label']);
            } elseif (is_object($opt) && isset($opt->name)) {
                $tid       = isset($opt->term_id) ? $opt->term_id : $key;
                $opt->name = $tr_term($tid, $opt->name);
            }
        }
        unset($opt);

        // Helper strings that aren't always routed through gettext.
        foreach ([
            'dropdown_placeholder', 'more_text', 'less_text', 'search_placeholder',
            'show_all_filter_label', 'show_all_placeholder', 'show_all_label',
            'filter_placeholder', 'apply_button_text', 'remove_filters_button_text'
        ] as $sk) {
            if (!empty($args[$sk]) && is_string($args[$sk])) {
                $args[$sk] = $tr_string($args[$sk]);
            }
        }

        return $args;
    }

    // =========================================================================
    // SEO: HREFLANG TAGS
    // =========================================================================
    
    /**
     * Output hreflang link tags in <head>
     */
    public function output_hreflang_tags() {
        if (!$this->is_active() || is_admin()) {
            return;
        }
        
        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }
        
        $translations = $this->get_post_translations($post_id);
        if (count($translations) < 2) {
            return;
        }
        
        echo "\n<!-- Meep Translator: hreflang tags -->\n";
        foreach ($translations as $lang_code => $trans_post_id) {
            $url = $this->get_post_permalink($trans_post_id);
            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr($lang_code),
                esc_url($url)
            );
        }
        
        // x-default points to default language version
        $default_lang = $this->get_default_language();
        if (isset($translations[$default_lang])) {
            printf(
                '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
                esc_url($this->get_post_permalink($translations[$default_lang]))
            );
        }
        echo "<!-- /Meep Translator: hreflang tags -->\n";
    }

    /**
     * Synchronizes key attributes (menu_order, post_date, post_date_gmt) from
     * the original post to all of its translated versions.
     */
    public function sync_translation_attributes($post_id, $post, $update) {
        if (!$this->is_active()) {
            return;
        }

        // Skip revisions, drafts, auto-drafts, and trashing/deleting operations
        if (wp_is_post_revision($post_id) || !$post || $post->post_status === 'auto-draft' || $post->post_status === 'trash') {
            return;
        }

        // Avoid infinite recursion loops by checking if this is already a translation
        $source_page = get_post_meta($post_id, '_aitrfoel_source_page', true);
        if (!empty($source_page)) {
            return; // This is a translation post, do not sync backwards
        }

        global $wpdb;
        $translations = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_aitrfoel_source_page' AND meta_value = %s",
                (string) $post_id
            )
        );

        if (empty($translations)) {
            return;
        }

        foreach ($translations as $tr_id) {
            $tr_post = get_post((int) $tr_id);
            if (!$tr_post) {
                continue;
            }

            $updates = [];
            if ($tr_post->menu_order !== $post->menu_order) {
                $updates['menu_order'] = $post->menu_order;
            }
            if ($tr_post->post_date !== $post->post_date) {
                $updates['post_date'] = $post->post_date;
            }
            if ($tr_post->post_date_gmt !== $post->post_date_gmt) {
                $updates['post_date_gmt'] = $post->post_date_gmt;
            }

            if (!empty($updates)) {
                $wpdb->update(
                    $wpdb->posts,
                    $updates,
                    ['ID' => (int) $tr_id]
                );
                clean_post_cache((int) $tr_id);
            }
        }
    }

    /**

    /**
     * Fixes JetProductGallery swiper container height issues on load.
     * Often, Swiper initializes before lazy-loaded images finish loading,
     * causing it to calculate a wrong/zero height until user interaction.
     */
    public function fix_jet_gallery_swiper_height() {
        ?>
        <script type="text/javascript">
        (function($) {
            if (typeof $ === 'undefined') return;
            $(window).on('load', function() {
                var updateSwipers = function() {
                    $('.swiper-container, .jet-swiper-container, .jet-gallery-swiper-slider').each(function() {
                        var swiper = this.swiper;
                        if (swiper) {
                            if (typeof swiper.update === 'function') swiper.update();
                            if (typeof swiper.updateAutoHeight === 'function') swiper.updateAutoHeight();
                        }
                    });
                    window.dispatchEvent(new Event('resize'));
                };
                setTimeout(updateSwipers, 300);
                setTimeout(updateSwipers, 800);
                setTimeout(updateSwipers, 1500);
                // Trigger whenever an image inside swiper finishes loading
                $('.swiper-container img, .jet-swiper-container img, .jet-gallery-swiper-slider img').on('load', function() {
                    var swiperEl = $(this).closest('.swiper-container, .jet-swiper-container, .jet-gallery-swiper-slider')[0];
                    if (swiperEl && swiperEl.swiper) {
                        if (typeof swiperEl.swiper.update === 'function') swiperEl.swiper.update();
                        if (typeof swiperEl.swiper.updateAutoHeight === 'function') swiperEl.swiper.updateAutoHeight();
                    }
                });
            });
        })(window.jQuery);
        </script>
        <?php
    }
    
    // =========================================================================
    // SITEMAP INTEGRATION
    // =========================================================================
    
    /**
     * Filter WordPress core sitemap queries to only include posts in the default
     * language. Translated posts are discoverable via hreflang tags on original pages.
     *
     * @param array  $args      WP_Query arguments for sitemap.
     * @param string $post_type Post type being queried.
     * @return array
     */
    public function filter_sitemap_query($args, $post_type) {
        if (!$this->is_active()) {
            return $args;
        }
        
        $default_lang = $this->get_default_language();
        if (!$default_lang) {
            return $args;
        }
        
        $tax_query = isset($args['tax_query']) ? $args['tax_query'] : [];
        $tax_query[] = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => $default_lang,
        ];
        $args['tax_query'] = $tax_query;
        
        return $args;
    }
    
    // =========================================================================
    // ADMIN: POSTS LIST LANGUAGE COLUMN
    // =========================================================================
    
    /**
     * Add language column to posts/pages list
     * @param array $columns
     * @return array
     */
    public function add_language_column($columns) {
        if (!$this->is_active()) {
            return $columns;
        }
        
        // Insert after title column
        $new_columns = [];
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ($key === 'title') {
                $new_columns['aitrfoel_language'] = '<span style="font-size:11px;">' . __('Lang', 'ai-translator-for-elementor-and-polylang') . '</span>';
            }
        }
        return $new_columns;
    }
    
    /**
     * Render language column content
     * @param string $column
     * @param int $post_id
     */
    public function render_language_column($column, $post_id) {
        if ($column !== 'aitrfoel_language') {
            return;
        }
        
        $lang = $this->get_post_language($post_id);
        if ($lang) {
            $default = $this->get_default_language();
            echo '<span style="font-size:11px;white-space:nowrap;">' . esc_html(strtoupper($lang));
            if ($lang === $default) {
                echo ' <small style="color:#888;">(' . esc_html__('def', 'ai-translator-for-elementor-and-polylang') . ')</small>';
            }
            echo '</span>';
        } else {
            echo '<span style="color: #ccc;">—</span>';
        }
    }
    
    /**
     * Output CSS to constrain the language column width
     */
    public function language_column_css() {
        echo '<style>.column-aitrfoel_language{width:50px!important;text-align:center;}</style>';
    }
    
    /**
     * Add a language filter dropdown to the admin posts/pages list.
     */
    public function admin_language_filter_dropdown() {
        if (!$this->is_active()) {
            return;
        }
        
        global $typenow;
        if (!in_array($typenow, ['post', 'page'], true)) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        if (empty($languages)) {
            return;
        }
        
        $selected = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        $default_lang = $this->get_default_language();
        $default_name = strtoupper($default_lang);
        foreach ($languages as $lang) {
            if ($lang['code'] === $default_lang) {
                $default_name = $lang['name'];
                break;
            }
        }
        
        // Preserve post_status when changing language filter (e.g. in Trash view)
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        
        // In Trash with no explicit language filter, default to "All Languages"
        if ($post_status === 'trash' && $selected === '') {
            $selected = 'all';
        }
        if ($post_status) {
            echo '<input type="hidden" name="post_status" value="' . esc_attr($post_status) . '">';
        }
        
        echo '<select name="aitrfoel_lang">';
        echo '<option value="">' . esc_html($default_name) . ' (' . esc_html__('default', 'ai-translator-for-elementor-and-polylang') . ')</option>';
        $all_sel = selected($selected, 'all', false);
        echo '<option value="all"' . $all_sel . '>' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang') . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of core selected()
        foreach ($languages as $lang) {
            if ($lang['code'] === $default_lang) {
                continue; // Already shown as default option
            }
            $sel = selected($selected, $lang['code'], false);
            echo '<option value="' . esc_attr($lang['code']) . '"' . $sel . '>' . esc_html($lang['name']) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output of core selected()
        }
        echo '</select>';
    }
    
    /**
     * Filter admin post queries by selected language.
     */
    public function admin_filter_posts_by_language($query) {
        if (!$this->is_active() || !is_admin() || !$query->is_main_query()) {
            return;
        }
        
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit') {
            return;
        }
        
        $lang = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        
        if ($lang === 'all') {
            // Show everything, no filtering
            return;
        }
        
        if (!empty($lang) && $this->language_exists($lang)) {
            // Specific language selected — filter by that taxonomy term
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $lang,
                'include_children' => false,
            ];
            $query->set('tax_query', $tax_query);
            return;
        }
        
        // In Trash view with no language filter, show all languages by default
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        if ($post_status === 'trash') {
            return;
        }
        
        // Default view (no param): exclude non-default language posts
        // This hides translations while showing default-language posts
        // (including untagged legacy posts)
        $non_default_langs = [];
        $default_lang = $this->get_default_language();
        foreach ($this->get_stored_languages() as $l) {
            if ($l['code'] !== $default_lang) {
                $non_default_langs[] = $l['code'];
            }
        }
        if (!empty($non_default_langs)) {
            $tax_query = $query->get('tax_query') ?: [];
            $tax_query[] = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $non_default_langs,
                'operator' => 'NOT IN',
                'include_children' => false,
            ];
            $query->set('tax_query', $tax_query);
        }
    }
    
    /**
     * Adjust post status view counts when a language filter is active.
     * Without this, the All/Published/Draft/Trash counts show totals across all languages.
     *
     * @param array $views Associative array of status links (HTML).
     * @return array Modified views with corrected counts.
     */
    public function filter_post_status_counts_by_language($views) {
        if (!$this->is_active()) {
            return $views;
        }
        
        $lang = isset($_GET['aitrfoel_lang']) ? sanitize_text_field($_GET['aitrfoel_lang']) : '';
        $post_status = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : '';
        
        // Only adjust counts when filtering by a specific language or default view
        if ($lang === 'all') {
            return $views;
        }
        
        global $typenow;
        $post_type = $typenow ?: 'post';
        $default_lang = $this->get_default_language();
        
        // In Trash with no lang filter, show all — no need to adjust
        if ($post_status === 'trash' && empty($lang)) {
            return $views;
        }
        
        // Build the tax_query for counting
        if (!empty($lang) && $this->language_exists($lang)) {
            // Specific language: count posts with this taxonomy term
            $tax_query = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $lang,
                    'include_children' => false,
                ],
            ];
        } else {
            // Default view: count posts NOT in non-default languages
            $non_default_langs = [];
            foreach ($this->get_stored_languages() as $l) {
                if ($l['code'] !== $default_lang) {
                    $non_default_langs[] = $l['code'];
                }
            }
            if (empty($non_default_langs)) {
                return $views;
            }
            $tax_query = [
                [
                    'taxonomy' => self::TAXONOMY,
                    'field'    => 'slug',
                    'terms'    => $non_default_langs,
                    'operator' => 'NOT IN',
                    'include_children' => false,
                ],
            ];
        }
        
        // Count posts per status with language filter
        $statuses = ['publish', 'draft', 'pending', 'trash', 'private'];
        $counts = [];
        $total = 0;
        
        foreach ($statuses as $status) {
            $q = new \WP_Query([
                'post_type'      => $post_type,
                'post_status'    => $status,
                'tax_query'      => $tax_query,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]);
            $counts[$status] = $q->found_posts;
            if ($status !== 'trash') {
                $total += $q->found_posts;
            }
        }
        
        // Update view links with corrected counts
        foreach ($views as $key => &$html) {
            if ($key === 'all') {
                $html = preg_replace('/\(<span class="count">\(?\d+\)?\s*<\/span>\)/', '(<span class="count">(' . $total . ')</span>)', $html);
                // Also fix plain count format
                $html = preg_replace('/class="count">\(\d+\)/', 'class="count">(' . $total . ')', $html);
            } elseif (isset($counts[$key])) {
                $html = preg_replace('/class="count">\(\d+\)/', 'class="count">(' . $counts[$key] . ')', $html);
            }
            
            // Remove status links with zero count (except current)
            if ($key !== 'all' && isset($counts[$key]) && $counts[$key] === 0) {
                unset($views[$key]);
            }
        }
        unset($html);
        
        return $views;
    }
    
    // =========================================================================
    // CLEANUP
    // =========================================================================
    
    /**
     * Remove all built-in language data (for uninstall)
     */
    public static function uninstall() {
        global $wpdb;
        
        // Drop translation groups table
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
        
        // Remove options
        delete_option(self::OPTION_LANGUAGES);
        delete_option(self::OPTION_DEFAULT_LANG);
        delete_option(self::OPTION_ENABLED);
        delete_option(self::OPTION_DB_VERSION);
        delete_option(self::OPTION_URL_STRUCTURE);
        
        // Remove taxonomy terms and associations
        $terms = get_terms(['taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'fields' => 'ids']);
        if (!is_wp_error($terms)) {
            foreach ($terms as $term_id) {
                wp_delete_term($term_id, self::TAXONOMY);
            }
        }
    }
    
    // =========================================================================
    // LANGUAGE SWITCHER WIDGET & SHORTCODE
    // =========================================================================
    
    /**
     * Register the language switcher shortcode
     */
    public function register_shortcode() {
        add_shortcode('aitrfoel_language_switcher', [$this, 'render_language_switcher_shortcode']);
    }
    
    /**
     * Register the language switcher widget
     */
    public function register_widget() {
        register_widget('AITRFOEL_Language_Switcher_Widget');
    }
    
    /**
     * Dropdown language switcher for nav menus: a parent item showing the current
     * language with the other languages as children.
     *
     * @param array    $items Menu item objects.
     * @param stdClass $args  wp_nav_menu() arguments.
     * @return array
     */
    public function add_language_switcher_menu_objects($items, $args) {
        if (is_admin() || !$this->is_active()) {
            return $items;
        }
        if (!get_option('aitrfoel_switcher_enabled', true) || !get_option('aitrfoel_nav_menu_switcher', false)) {
            return $items;
        }
        if (get_option('aitrfoel_nav_menu_style', 'list') !== 'dropdown') {
            return $items;
        }
        $location = get_option('aitrfoel_nav_menu_location', '');
        if ($location) {
            $theme_location = isset($args->theme_location) ? (string) $args->theme_location : '';
            // Themes like Astra render a separate mobile menu location; keep the switcher there too.
            $mobile_locations = ['mobile_menu', 'mobile', 'mobile-menu', 'handheld', 'off_canvas', 'offcanvas'];
            if ($theme_location !== $location && !in_array($theme_location, $mobile_locations, true)) {
                return $items;
            }
        }

        $languages = $this->get_language_switcher_data();
        if (count($languages) < 2) {
            return $items;
        }
        $current = null;
        foreach ($languages as $lang) {
            if (!empty($lang['is_current'])) {
                $current = $lang;
                break;
            }
        }
        if (!$current) {
            $current = $languages[0];
        }

        $show_flags = get_option('aitrfoel_switcher_show_flags', true);
        $base_id    = 990000000;
        $order      = count($items) + 1;

        $parent = $this->make_switcher_menu_item($base_id, 0, $order++, $current, $show_flags);
        $parent->classes[] = 'menu-item-has-children';
        $parent->classes[] = 'current-language';
        $items[] = $parent;

        foreach ($languages as $i => $lang) {
            if ($lang['code'] === $current['code']) {
                continue;
            }
            $items[] = $this->make_switcher_menu_item($base_id + 1 + $i, $base_id, $order++, $lang, $show_flags);
        }

        return $items;
    }

    /**
     * Build a nav-menu-item-like object for the language switcher.
     */
    private function make_switcher_menu_item($id, $parent_id, $order, $lang, $show_flags) {
        $flag = $show_flags && class_exists('AITRFOEL_Language_Mapper')
            ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 16, 'margin-right:6px;vertical-align:-2px;')
            : '';
        $item = new stdClass();
        $item->ID                    = $id;
        $item->db_id                 = $id;
        $item->menu_item_parent      = (string) $parent_id;
        $item->object_id             = (string) $id;
        $item->object                = 'custom';
        $item->type                  = 'custom';
        $item->type_label            = 'Custom Link';
        $item->post_type             = 'nav_menu_item';
        $item->post_parent           = 0;
        $item->post_status           = 'publish';
        $item->post_title            = '';
        $item->post_name             = 'aitrfoel-lang-' . sanitize_key($lang['code']);
        $item->post_content          = '';
        $item->post_excerpt          = '';
        $item->filter                = 'raw';
        $item->menu_order            = $order;
        $item->title                 = $flag . esc_html($this->get_language_display_name($lang));
        $item->url                   = $lang['url'];
        $item->target                = '';
        $item->attr_title            = '';
        $item->description           = '';
        $item->xfn                   = '';
        $item->current               = false;
        $item->current_item_ancestor = false;
        $item->current_item_parent   = false;
        $item->classes               = ['menu-item', 'menu-item-type-custom', 'menu-item-language', 'aitrfoel-menu-language', 'menu-item-language-' . sanitize_html_class($lang['code'])];
        return $item;
    }

    /**
     * Append language switcher links to a nav menu if the theme location
     * is configured via the 'aitrfoel_nav_menu_switcher' option.
     *
     * Enable with: update_option('aitrfoel_nav_menu_switcher', true);
     *
     * @param string   $items  HTML menu items.
     * @param stdClass $args   Menu display arguments.
     * @return string
     */
    public function add_language_switcher_to_menu($items, $args) {
        if (!$this->is_active()) {
            return $items;
        }
        
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return $items;
        }
        
        // Only add to menus if enabled via option
        $enabled = get_option('aitrfoel_nav_menu_switcher', false);
        if (!$enabled) {
            return $items;
        }

        // The dropdown style is added as menu objects instead (see add_language_switcher_menu_objects).
        if (get_option('aitrfoel_nav_menu_style', 'list') === 'dropdown') {
            return $items;
        }
        
        // Optionally restrict to specific menu location
        $location = get_option('aitrfoel_nav_menu_location', '');
        if ($location && $args->theme_location !== $location) {
            return $items;
        }
        
        $switcher_data = $this->get_language_switcher_data();
        if (empty($switcher_data)) {
            return $items;
        }
        
        $show_flags = get_option('aitrfoel_switcher_show_flags', true);
        $show_names = get_option('aitrfoel_switcher_show_names', true);
        
        foreach ($switcher_data as $lang) {
            $class = 'menu-item menu-item-language';
            if ($lang['is_current']) {
                $class .= ' current-language';
            }
            $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 16, 'margin-right: 4px;') : '';
            $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
            if (!$show_flags && !$show_names) {
                $name_html = esc_html(strtoupper($lang['code']));
            }
            $items .= sprintf(
                '<li class="%s"><a href="%s">%s%s</a></li>',
                esc_attr($class),
                esc_url($lang['url']),
                $flag_html,
                $name_html
            );
        }
        
        return $items;
    }
    
    /**
     * Register REST API fields for language and translations on posts/pages.
     */
    public function register_rest_fields() {
        $post_types = array_keys(get_post_types(['public' => true], 'names'));
        $excluded_types = [
            'attachment', 'nav_menu_item', 'revision', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request',
            'sureforms', 'sureforms_form', 'sf_form', 'sf_submission', 'sureforms_submission', 'jet-form-builder'
        ];
        $post_types = array_values(array_diff($post_types, $excluded_types));
        
        foreach ($post_types as $post_type) {
            // Language field
            register_rest_field($post_type, 'language', [
                'get_callback' => function ($object) {
                    return $this->get_post_language($object['id']) ?: $this->get_default_language();
                },
                'update_callback' => function ($value, $object) {
                    if ($value && $this->language_exists($value)) {
                        $this->set_post_language($object->ID, $value);
                    }
                },
                'schema' => [
                    'description' => __('Post language code', 'ai-translator-for-elementor-and-polylang'),
                    'type'        => 'string',
                    'context'     => ['view', 'edit'],
                ],
            ]);
            
            // Translations field (read-only)
            register_rest_field($post_type, 'translations', [
                'get_callback' => function ($object) {
                    $translations = $this->get_post_translations($object['id']);
                    $result = [];
                    foreach ($translations as $lang_code => $post_id) {
                        $result[$lang_code] = [
                            'post_id'   => $post_id,
                            'permalink' => $this->get_post_permalink($post_id),
                        ];
                    }
                    return $result;
                },
                'schema' => [
                    'description' => __('Translations of this post', 'ai-translator-for-elementor-and-polylang'),
                    'type'        => 'object',
                    'context'     => ['view', 'edit'],
                    'readonly'    => true,
                ],
            ]);
        }
    }
    
    /**
     * Get the display name for a language based on settings
     * 
     * @param array $lang Language data with code, name, native_name keys
     * @return string The formatted display name
     */
    private function get_language_display_name($lang) {
        return self::resolve_language_display_name($lang);
    }
    
    /**
     * Static version for use in settings preview
     * 
     * @param array $lang Language data with code, name, native_name keys
     * @return string The formatted display name
     */
    public static function resolve_language_display_name($lang) {
        // Check custom names first
        $custom_names = get_option('aitrfoel_switcher_custom_names', []);
        if (!empty($custom_names[$lang['code']])) {
            return $custom_names[$lang['code']];
        }
        
        $format = get_option('aitrfoel_switcher_name_display', 'native');
        
        switch ($format) {
            case 'english':
                return $lang['name'] ?? strtoupper($lang['code']);
            case 'code':
                return strtoupper($lang['code']);
            case 'code_lower':
                return strtolower($lang['code']);
            case 'native':
            default:
                $native = isset($lang['native_name']) ? (string) $lang['native_name'] : '';
                $name   = isset($lang['name']) ? (string) $lang['name'] : '';
                // Languages added by the wizard/settings store the English name as the
                // native name; fall back to a built-in endonym table in that case.
                if ($native === '' || $native === $name) {
                    $endonym = self::get_native_language_name($lang['code'] ?? '');
                    if ($endonym !== '') {
                        return $endonym;
                    }
                }
                return $native !== '' ? $native : ($name !== '' ? $name : strtoupper((string) ($lang['code'] ?? '')));
        }
    }

    /**
     * Native name (endonym) for common language codes, or '' if unknown.
     *
     * @param string $code Language code, e.g. 'de', 'pt-br', 'zh-tw'.
     * @return string
     */
    public static function get_native_language_name($code) {
        static $map = [
            'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español', 'it' => 'Italiano',
            'pt' => 'Português', 'pt-br' => 'Português (Brasil)', 'nl' => 'Nederlands', 'pl' => 'Polski',
            'uk' => 'Українська', 'ru' => 'Русский', 'be' => 'Беларуская', 'cs' => 'Čeština', 'sk' => 'Slovenčina',
            'sl' => 'Slovenščina', 'hr' => 'Hrvatski', 'sr' => 'Српски', 'bs' => 'Bosanski', 'bg' => 'Български',
            'mk' => 'Македонски', 'ro' => 'Română', 'hu' => 'Magyar', 'el' => 'Ελληνικά', 'tr' => 'Türkçe',
            'da' => 'Dansk', 'sv' => 'Svenska', 'nb' => 'Norsk bokmål', 'no' => 'Norsk', 'fi' => 'Suomi',
            'is' => 'Íslenska', 'et' => 'Eesti', 'lv' => 'Latviešu', 'lt' => 'Lietuvių', 'ga' => 'Gaeilge',
            'ca' => 'Català', 'eu' => 'Euskara', 'gl' => 'Galego', 'sq' => 'Shqip', 'mt' => 'Malti',
            'ka' => 'ქართული', 'hy' => 'Հայերեն', 'az' => 'Azərbaycan', 'kk' => 'Қазақ', 'uz' => 'Oʻzbek',
            'ar' => 'العربية', 'he' => 'עברית', 'fa' => 'فارسی', 'ur' => 'اردو', 'hi' => 'हिन्दी',
            'bn' => 'বাংলা', 'ta' => 'தமிழ்', 'te' => 'తెలుగు', 'mr' => 'मराठी', 'th' => 'ไทย',
            'vi' => 'Tiếng Việt', 'id' => 'Bahasa Indonesia', 'ms' => 'Bahasa Melayu', 'tl' => 'Tagalog',
            'zh' => '中文', 'zh-cn' => '简体中文', 'zh-tw' => '繁體中文', 'ja' => '日本語', 'ko' => '한국어',
            'sw' => 'Kiswahili', 'af' => 'Afrikaans',
        ];
        $code = strtolower(str_replace('_', '-', (string) $code));
        if (isset($map[$code])) {
            return $map[$code];
        }
        $short = substr($code, 0, 2);
        return isset($map[$short]) ? $map[$short] : '';
    }
    
    /**
     * Render language switcher via shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function render_language_switcher_shortcode($atts = []) {
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return '';
        }
        
        $atts = shortcode_atts([
            'style' => get_option('aitrfoel_switcher_style', 'flags-text'),
            'show_names' => get_option('aitrfoel_switcher_show_names', true) ? 'true' : 'false',
            'show_flags' => get_option('aitrfoel_switcher_show_flags', true) ? 'true' : 'false',
            'theme' => get_option('aitrfoel_switcher_theme', 'default'),
            'dropdown_names' => get_option('aitrfoel_switcher_dropdown_names', true) ? 'true' : 'false',
            'mobile_names' => get_option('aitrfoel_switcher_mobile_names', false) ? 'true' : 'false',
        ], $atts);

        // Visual theme variant (applies mainly to the dropdown trigger).
        $allowed_themes = ['default', 'minimal', 'light', 'pill'];
        $switcher_theme = in_array($atts['theme'], $allowed_themes, true) ? $atts['theme'] : 'default';
        $switcher_theme_class = ' aitrfoel-theme-' . $switcher_theme;

        // Whether the dropdown LIST shows language names (independent of the trigger,
        // so you can have a flag-only trigger with named entries — or flags only).
        $dropdown_names = ($atts['dropdown_names'] === 'true' || $atts['dropdown_names'] === '1');

        // Show the current language NAME in the trigger on mobile only (where the
        // off-canvas menu has room), while keeping the desktop header compact.
        $mobile_names = ($atts['mobile_names'] === 'true' || $atts['mobile_names'] === '1');
        
        $switcher_data = $this->get_language_switcher_data();
        if (empty($switcher_data) || count($switcher_data) < 2) {
            return '';
        }
        
        // Enqueue frontend switcher styles
        $this->enqueue_switcher_frontend_styles();
        
        ob_start();
        
        $show_flags = ($atts['show_flags'] === 'true' || $atts['show_flags'] === '1');
        $show_names = ($atts['show_names'] === 'true' || $atts['show_names'] === '1');
        
        if ($atts['style'] === 'list') {
            echo '<ul class="aitrfoel-language-switcher aitrfoel-switcher-list">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 6px;') : '';
                $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
                if (!$show_flags && !$show_names) {
                    $name_html = esc_html(strtoupper($lang['code']));
                }
                printf(
                    '<li class="aitrfoel-switcher-item%s"><a href="%s">%s%s</a></li>',
                    esc_attr($active_class),
                    esc_url($lang['url']),
                    $flag_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style
                    $name_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built
                );
            }
            echo '</ul>';
        } elseif ($atts['style'] === 'flags') {
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-flags">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 24, '');
                printf(
                    '<a href="%s" class="aitrfoel-flag-link%s" title="%s">%s</a>',
                    esc_url($lang['url']),
                    esc_attr($active_class),
                    esc_attr($lang['name']),
                    $flag_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style
                );
            }
            echo '</div>';
        } elseif ($atts['style'] === 'flags-text') {
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-flags-text">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 4px;') : '';
                $name_html = $show_names ? esc_html($this->get_language_display_name($lang)) : '';
                if (!$show_flags && !$show_names) {
                    $name_html = esc_html(strtoupper($lang['code']));
                }
                printf(
                    '<a href="%s" class="aitrfoel-flag-text-link%s" title="%s">%s%s</a>',
                    esc_url($lang['url']),
                    esc_attr($active_class),
                    esc_attr($lang['name']),
                    $flag_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style
                    $name_html ? '<span>' . $name_html . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built
                );
            }
            echo '</div>';
        } else {
            // Custom dropdown with flag images (native <select> can't render HTML)
            $current_lang = null;
            foreach ($switcher_data as $lang) {
                if ($lang['is_current']) { $current_lang = $lang; break; }
            }
            if (!$current_lang) $current_lang = $switcher_data[0];
            
            $current_flag = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($current_lang['code'], 20, 'margin-right: 6px;') : '';
            $current_label = $show_names ? esc_html($this->get_language_display_name($current_lang)) : '';
            if (!$show_flags && !$show_names) {
                $current_label = esc_html(strtoupper($current_lang['code']));
            }
            
            // Compact trigger when no name is shown (flag-only) so it isn't forced to
            // a fixed width. The dropdown list still shows language names for clarity.
            $compact_class = $show_names ? '' : ' aitrfoel-switcher-compact';
            $mobile_names_class = ($mobile_names && !$show_names) ? ' aitrfoel-switcher-mobile-names' : '';
            echo '<div class="aitrfoel-language-switcher aitrfoel-switcher-dropdown-wrapper' . esc_attr($switcher_theme_class . $compact_class . $mobile_names_class) . '">';
            echo '<button type="button" class="aitrfoel-dropdown-trigger" aria-expanded="false" aria-haspopup="listbox">';
            echo $current_flag . '<span>' . $current_label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style; already escaped with esc_html()/esc_attr() where the value is built
            if ($mobile_names && !$show_names) {
                // Name shown only at mobile breakpoints via CSS (hidden on desktop).
                echo '<span class="aitrfoel-mobile-name">' . esc_html($this->get_language_display_name($current_lang)) . '</span>';
            }
            echo '<svg class="aitrfoel-dropdown-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            echo '</button>';
            echo '<ul class="aitrfoel-dropdown-list" role="listbox" style="display:none;">';
            foreach ($switcher_data as $lang) {
                $active_class = $lang['is_current'] ? ' active' : '';
                $flag_html = $show_flags ? AITRFOEL_Language_Mapper::get_flag_html($lang['code'], 20, 'margin-right: 6px;') : '';
                // Dropdown list label honours the "Show names in dropdown" setting. If
                // names are off and there are no flags either, fall back to the code so
                // the entries are never blank.
                if ($dropdown_names) {
                    $label = esc_html($this->get_language_display_name($lang));
                } elseif (!$show_flags) {
                    $label = esc_html(strtoupper($lang['code']));
                } else {
                    $label = '';
                }
                printf(
                    '<li role="option" class="aitrfoel-dropdown-item%s"><a href="%s">%s%s</a></li>',
                    esc_attr($active_class),
                    esc_url($lang['url']),
                    $flag_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- flag <img> from AITRFOEL_Language_Mapper::get_flag_html(), which escapes its URL, alt and style
                    $label // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped with esc_html()/esc_attr() where the value is built
                );
            }
            echo '</ul>';
            echo '</div>';
            
            // Inline JS for dropdown toggle enqueued in footer to prevent themes/builders from stripping script tags.
            $this->register_switcher_javascript();
        }

        // Wrap in no-localize markers so localize_internal_links does not
        // rewrite the intentional cross-language href targets of the switcher.
        return '<!-- aitrfoel-no-localize -->' . ob_get_clean() . '<!-- /aitrfoel-no-localize -->';
    }
    
    /**
     * Output switcher CSS in <head> if nav menu switcher is enabled 
     * (since the shortcode may also appear on any page via widget/menu).
     * If the nav menu switcher is active, we always need styles loaded.
     */
    public function maybe_preload_switcher_css() {
        if (is_admin()) return;
        // If nav menu switcher is enabled, always preload CSS
        $nav_enabled = get_option('aitrfoel_nav_menu_switcher', false);
        if ($nav_enabled) {
            $this->enqueue_switcher_frontend_styles();
            add_action('wp_footer', [$this, 'output_menu_switcher_contrast_fix'], 100);
        }
    }

    /**
     * The nav-menu switcher's dropdown uses the theme's own sub-menu markup. Some theme
     * setups (e.g. Astra's transparent header) give menu links white text while the
     * dropdown panel stays white, so the language names are invisible. If a link's colour
     * is too close to its panel's background, switch it to dark or white text.
     */
    public function output_menu_switcher_contrast_fix() {
        ?>
<script id="aitrfoel-menu-switcher-contrast">
(function(){
    function rgb(s){var m=String(s).match(/[\d.]+/g);return m?m.map(Number):null;}
    function lum(c){var a=c.slice(0,3).map(function(v){v/=255;return v<=0.03928?v/12.92:Math.pow((v+0.055)/1.055,2.4);});return 0.2126*a[0]+0.7152*a[1]+0.0722*a[2];}
    function bgOf(el){while(el&&el!==document.documentElement){var c=rgb(getComputedStyle(el).backgroundColor);if(c&&(c.length<4||c[3]>0.5))return c;el=el.parentElement;}return [255,255,255];}
    function fix(){
        document.querySelectorAll('.menu-item-language .sub-menu a, .menu-item-language .children a').forEach(function(a){
            var fg=rgb(getComputedStyle(a).color); if(!fg) return;
            var bg=bgOf(a.parentElement), lf=lum(fg), lb=lum(bg);
            var ratio=(Math.max(lf,lb)+0.05)/(Math.min(lf,lb)+0.05);
            if(ratio<2){ a.style.setProperty('color', lb>0.5?'#1f2937':'#ffffff', 'important'); }
        });
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fix);}else{fix();}
})();
</script>
        <?php
    }
    
    /**
     * Enqueue language switcher frontend styles (inline CSS + custom CSS)
     */
    public function enqueue_switcher_frontend_styles() {
        static $enqueued = false;
        if ($enqueued) return;
        $enqueued = true;
        
        $default_css = '
/* AI Translator Language Switcher - CSS Custom Properties for theme overridability */
.aitrfoel-language-switcher {
    font-family: inherit;
    --aitrfoel-text-color: currentColor;
    --aitrfoel-text-hover-color: currentColor;
    --aitrfoel-active-bg: #0073aa;
    --aitrfoel-active-color: #fff;
    --aitrfoel-hover-bg: rgba(0,0,0,0.06);
    --aitrfoel-border-color: rgba(0,0,0,0.15);
    --aitrfoel-bg: transparent;
    --aitrfoel-dropdown-bg: #fff;
    --aitrfoel-dropdown-shadow: 0 4px 16px rgba(0,0,0,0.12);
}

/* List style */
.aitrfoel-switcher-list { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 4px; }
.aitrfoel-switcher-list .aitrfoel-switcher-item { margin: 0; }
.aitrfoel-switcher-list .aitrfoel-switcher-item a { display: flex; align-items: center; padding: 6px 12px; text-decoration: none; color: var(--aitrfoel-text-color); border-radius: 6px; transition: background-color 0.2s, color 0.2s; font-size: 14px; }
.aitrfoel-switcher-list .aitrfoel-switcher-item a:hover { background-color: var(--aitrfoel-hover-bg); color: var(--aitrfoel-text-hover-color); }
.aitrfoel-switcher-list .aitrfoel-switcher-item.active a { background-color: var(--aitrfoel-active-bg); color: var(--aitrfoel-active-color); font-weight: 600; }
.aitrfoel-switcher-list .aitrfoel-switcher-item.active a img { box-shadow: 0 0 0 2px rgba(255,255,255,0.5); border-radius: 2px; }

/* Flags only style */
.aitrfoel-switcher-flags { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.aitrfoel-flag-link { display: inline-flex; align-items: center; padding: 4px; border-radius: 4px; transition: transform 0.15s, box-shadow 0.15s; text-decoration: none; }
.aitrfoel-flag-link:hover { transform: scale(1.15); box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
.aitrfoel-flag-link.active { box-shadow: 0 0 0 2px var(--aitrfoel-active-bg); border-radius: 4px; }
.aitrfoel-flag-link img { display: block; border-radius: 2px; }

/* Flags + text style */
.aitrfoel-switcher-flags-text { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.aitrfoel-flag-text-link { display: inline-flex; align-items: center; padding: 4px 10px; text-decoration: none; color: var(--aitrfoel-text-color); border-radius: 6px; font-size: 13px; font-weight: 500; transition: background-color 0.2s; }
.aitrfoel-flag-text-link:hover { background-color: var(--aitrfoel-hover-bg); }
.aitrfoel-flag-text-link.active { background-color: var(--aitrfoel-active-bg); color: var(--aitrfoel-active-color); }
.aitrfoel-flag-text-link.active img { box-shadow: 0 0 0 1px rgba(255,255,255,0.5); }

/* Custom dropdown style */
.aitrfoel-switcher-dropdown-wrapper { display: inline-block; position: relative; }
.aitrfoel-dropdown-trigger { display: inline-flex; align-items: center; gap: 4px; padding: 6px 12px; border: 1px solid var(--aitrfoel-border-color); border-radius: 6px; font-size: 14px; font-family: inherit; background: var(--aitrfoel-bg); color: var(--aitrfoel-text-color); cursor: pointer; min-width: 120px; }
.aitrfoel-dropdown-trigger:hover { border-color: var(--aitrfoel-active-bg); }
.aitrfoel-dropdown-arrow { margin-left: auto; transition: transform 0.2s; flex-shrink: 0; }
.aitrfoel-dropdown-trigger[aria-expanded="true"] .aitrfoel-dropdown-arrow { transform: rotate(180deg); }
.aitrfoel-dropdown-list { position: absolute; top: 100%; left: 0; z-index: 9999; min-width: 100%; margin: 4px 0 0; padding: 4px 0; list-style: none; background: var(--aitrfoel-dropdown-bg); border: 1px solid var(--aitrfoel-border-color); border-radius: 6px; box-shadow: var(--aitrfoel-dropdown-shadow); }
.aitrfoel-dropdown-item a { display: flex; align-items: center; padding: 6px 12px; text-decoration: none; color: var(--aitrfoel-text-color); font-size: 14px; white-space: nowrap; transition: background-color 0.15s; }
.aitrfoel-dropdown-item a:hover { background-color: var(--aitrfoel-hover-bg); }
.aitrfoel-dropdown-item.active a { font-weight: 600; color: var(--aitrfoel-active-bg); }

/* Compact trigger (flag-only): size to content instead of a fixed 120px width. */
.aitrfoel-switcher-compact .aitrfoel-dropdown-trigger { min-width: 0; gap: 2px; padding: 4px 8px; }
.aitrfoel-switcher-compact .aitrfoel-dropdown-trigger > span:empty { display: none; }
.aitrfoel-switcher-compact .aitrfoel-dropdown-list { min-width: 160px; }

/* Theme variants (apply to the dropdown trigger) */
.aitrfoel-theme-minimal .aitrfoel-dropdown-trigger { border-color: transparent; background: transparent; padding: 4px 8px; }
.aitrfoel-theme-minimal .aitrfoel-dropdown-trigger:hover { border-color: transparent; background: var(--aitrfoel-hover-bg); }
.aitrfoel-theme-light .aitrfoel-dropdown-trigger { border-color: rgba(0,0,0,0.08); }
.aitrfoel-theme-pill .aitrfoel-dropdown-trigger { border-radius: 999px; padding-left: 14px; padding-right: 14px; }
.aitrfoel-theme-pill:not(.aitrfoel-switcher-compact) .aitrfoel-dropdown-trigger { padding-right: 32px; }
.aitrfoel-theme-pill .aitrfoel-dropdown-list { border-radius: 12px; }

/* Mobile: show the language name in the trigger (off-canvas menu has room) while the
   desktop header stays flag-only. */
.aitrfoel-mobile-name { display: none; }
@media (max-width: 921px) {
    .aitrfoel-switcher-mobile-names .aitrfoel-mobile-name { display: inline; margin-left: 6px; }
    .aitrfoel-switcher-mobile-names .aitrfoel-dropdown-trigger { min-width: 0; }
}
';
        
        $full_css = $default_css;

        // Option-driven overrides: border colour + flag size (px).
        $border_color = sanitize_hex_color(get_option('aitrfoel_switcher_border_color', ''));
        if ($border_color) {
            $full_css .= "\n.aitrfoel-language-switcher { --aitrfoel-border-color: " . $border_color . "; }";
        }
        $flag_size = (int) get_option('aitrfoel_switcher_flag_size', 0);
        if ($flag_size >= 8 && $flag_size <= 128) {
            $full_css .= "\n.aitrfoel-language-switcher img { width: " . $flag_size . "px !important; height: auto !important; }";
        }

        // Get custom CSS from settings
        $custom_css = get_option('aitrfoel_switcher_custom_css', '');
        if (!empty(trim($custom_css))) {
            $full_css .= "\n/* Custom Language Switcher CSS */\n" . $custom_css;
        }
        
        // Output in <head> via wp_head hook if possible, otherwise inline
        if (!did_action('wp_head') || doing_action('wp_head')) {
            echo '<style id="aitrfoel-language-switcher-css">' . $full_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS only: built-in rules, sanitize_hex_color/(int) values, custom CSS sanitized on save with wp_strip_all_tags
        } else {
            // Shortcode rendered after wp_head (e.g., in content) — output inline
            echo '<style id="aitrfoel-language-switcher-css">' . $full_css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS only: built-in rules, sanitize_hex_color/(int) values, custom CSS sanitized on save with wp_strip_all_tags
        }
    }
    
    /**
     * Add language switcher to the WordPress admin bar / toolbar.
     * Shows the current language with a flag, and a dropdown of other languages.
     *
     * @param WP_Admin_Bar $wp_admin_bar
     */
    public function add_admin_bar_language_switcher($wp_admin_bar) {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_stored_languages();
        if (count($languages) < 2) {
            return;
        }
        
        $default_lang = $this->get_default_language();
        $current_lang = $default_lang;
        
        // Detect current language from the request
        if (is_admin()) {
            if (isset($_GET['aitrfoel_lang'])) {
                $admin_lang = sanitize_text_field($_GET['aitrfoel_lang']);
                if ($admin_lang === 'all') {
                    $current_lang = 'all';
                } elseif ($this->language_exists($admin_lang)) {
                    $current_lang = $admin_lang;
                }
            } elseif (isset($_GET['post_status']) && $_GET['post_status'] === 'trash') {
                // In Trash with no explicit language filter, show as "All Languages"
                $current_lang = 'all';
            }
        } else {
            $queried_lang = get_query_var('lang', '');
            if ($queried_lang && $this->language_exists($queried_lang)) {
                $current_lang = $queried_lang;
            }
        }
        
        // Find current language info
        if ($current_lang === 'all') {
            $current_lang_name = __('All Languages', 'ai-translator-for-elementor-and-polylang');
        } else {
            $current_lang_name = strtoupper($current_lang);
            foreach ($languages as $lang) {
                if ($lang['code'] === $current_lang) {
                    $current_lang_name = $lang['name'];
                    break;
                }
            }
        }
        
        // Get flag HTML for the current language
        $flag_html = '';
        if ($current_lang === 'all') {
            $flag_html = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
        } elseif (class_exists('AITRFOEL_Language_Mapper')) {
            $flag_url = AITRFOEL_Language_Mapper::get_flag_url($current_lang, 20);
            $flag_html = '<img src="' . esc_url($flag_url) . '" alt="" style="vertical-align:middle;margin-right:4px;width:16px;height:12px;border-radius:2px;">';
        }
        
        // Parent node: current language
        $wp_admin_bar->add_node([
            'id'    => 'aitrfoel-lang-switcher',
            'title' => $flag_html . '<span class="ab-label">' . esc_html($current_lang_name) . '</span>',
            'href'  => false,
            'meta'  => [
                'class' => 'aitrfoel-admin-bar-lang',
            ],
        ]);
        
        // "All Languages" option (first item)
        $all_href = home_url('/');
        if (is_admin()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post_type = ($screen && $screen->post_type) ? $screen->post_type : 'post';
            $admin_status = isset($_GET['post_status']) ? '&post_status=' . sanitize_text_field($_GET['post_status']) : '';
            $all_href = admin_url('edit.php?post_type=' . $post_type . '&aitrfoel_lang=all' . $admin_status);
        }
        $wp_admin_bar->add_node([
            'id'     => 'aitrfoel-lang-all',
            'parent' => 'aitrfoel-lang-switcher',
            'title'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>' . esc_html__('All Languages', 'ai-translator-for-elementor-and-polylang'),
            'href'   => $all_href,
            'meta'   => [
                'class' => 'aitrfoel-lang-all',
            ],
        ]);

        // Child nodes: individual languages
        foreach ($languages as $lang) {
            $lang_flag = '';
            if (class_exists('AITRFOEL_Language_Mapper')) {
                $lang_flag_url = AITRFOEL_Language_Mapper::get_flag_url($lang['code'], 20);
                $lang_flag = '<img src="' . esc_url($lang_flag_url) . '" alt="" style="vertical-align:middle;margin-right:4px;width:16px;height:12px;border-radius:2px;">';
            }
            
            // Build URL for this language
            if (is_admin()) {
                // In admin, filter the posts list by language
                $screen = function_exists('get_current_screen') ? get_current_screen() : null;
                $post_type = ($screen && $screen->post_type) ? $screen->post_type : 'post';
                $admin_status = isset($_GET['post_status']) ? '&post_status=' . sanitize_text_field($_GET['post_status']) : '';
                if ($lang['code'] === $default_lang) {
                    $url = admin_url('edit.php?post_type=' . $post_type . $admin_status);
                } else {
                    $url = admin_url('edit.php?post_type=' . $post_type . '&aitrfoel_lang=' . $lang['code'] . $admin_status);
                }
            } else {
                // On frontend, try to get the same page in this language
                $current_post_id = get_queried_object_id();
                if ($current_post_id) {
                    $translations = $this->get_post_translations($current_post_id);
                    if (isset($translations[$lang['code']])) {
                        $url = get_permalink($translations[$lang['code']]);
                    } else {
                        $url = ($lang['code'] === $default_lang) ? home_url('/') : home_url('/' . $lang['code'] . '/');
                    }
                } else {
                    $url = ($lang['code'] === $default_lang) ? home_url('/') : home_url('/' . $lang['code'] . '/');
                }
            }
            
            $is_current = ($lang['code'] === $current_lang);
            
            $wp_admin_bar->add_node([
                'id'     => 'aitrfoel-lang-' . $lang['code'],
                'parent' => 'aitrfoel-lang-switcher',
                'title'  => $lang_flag . esc_html($lang['name']) . ($is_current ? ' (Current)' : ''),
                'href'   => esc_url($url),
                'meta'   => [
                    'class' => $is_current ? 'aitrfoel-lang-current' : '',
                ],
            ]);
        }
    }
    
    // =========================================================================
    // PER-LANGUAGE MENU ASSIGNMENT
    // =========================================================================
    
    /**
     * Output CSS for the admin bar language switcher to ensure inline layout.
     * Some themes override admin bar styles causing flag + text to stack vertically.
     */
    public function admin_bar_switcher_css() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style id="aitrfoel-admin-bar-lang-css">'
            . '#wpadminbar .aitrfoel-admin-bar-lang > .ab-item { display: flex !important; align-items: center !important; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang > .ab-item img { flex-shrink: 0; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang .ab-submenu .ab-item { display: flex !important; align-items: center !important; }'
            . '#wpadminbar .aitrfoel-admin-bar-lang .ab-submenu .ab-item img { flex-shrink: 0; }'
            . '</style>';
    }

    /**
     * Filter wp_nav_menu_args to swap the menu based on current language.
     * Uses option 'aitrfoel_menu_assignments' which stores:
     * [ 'primary' => ['de' => 42, 'fr' => 43], 'footer' => ['de' => 44] ]
     * where keys are theme_location slugs and values map lang code → menu term_id.
     *
     * @param array $args wp_nav_menu arguments.
     * @return array
     */
    public function filter_nav_menu_by_language($args) {
        if (!$this->is_active()) {
            return $args;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        $assignments = get_option('aitrfoel_menu_assignments', []);
        if (!is_array($assignments)) {
            $assignments = [];
        }
        
        // Try by theme location first
        $location = isset($args['theme_location']) ? $args['theme_location'] : '';
        if (!empty($location) && !empty($assignments) && isset($assignments[$location][$current_lang])) {
            $menu_id = intval($assignments[$location][$current_lang]);
            if ($menu_id > 0) {
                $args['menu'] = $menu_id;
            }
            return $args;
        }
        
        // Fallback: match by menu ID/slug/object across all location assignments
        $nav_menu_locations = get_nav_menu_locations();
        $requested_menu = isset($args['menu']) ? $args['menu'] : '';
        if (!empty($requested_menu)) {
            $requested_menu_obj = wp_get_nav_menu_object($requested_menu);
            $requested_menu_id = $requested_menu_obj ? $requested_menu_obj->term_id : 0;
            
            if ($requested_menu_id) {
                // Check location assignments
                foreach ($nav_menu_locations as $loc => $loc_menu_id) {
                    if ((int) $loc_menu_id === $requested_menu_id && isset($assignments[$loc][$current_lang])) {
                        $translated_menu_id = intval($assignments[$loc][$current_lang]);
                        if ($translated_menu_id > 0) {
                            $args['menu'] = $translated_menu_id;
                            return $args;
                        }
                    }
                }
                
                // Check widget translations for nav_menu widgets
                $widget_translations = get_option('aitrfoel_widget_translations', []);
                foreach ($widget_translations as $wid => $lang_data) {
                    if (isset($lang_data[$current_lang]['nav_menu'])) {
                        // Verify this widget's source menu matches the requested menu
                        if (preg_match('/^nav_menu-(\d+)$/', $wid, $wm)) {
                            $widget_instances = get_option('widget_nav_menu', []);
                            $widget_num = (int) $wm[1];
                            if (isset($widget_instances[$widget_num]['nav_menu']) && (int) $widget_instances[$widget_num]['nav_menu'] === $requested_menu_id) {
                                $args['menu'] = intval($lang_data[$current_lang]['nav_menu']);
                                return $args;
                            }
                        }
                    }
                }
                
                // Final fallback: direct menu ID map (handles Elementor Nav Menu widgets
                // and other menus not assigned to any theme location). Build it
                // bidirectionally so maps created before a default-language switch still
                // work when the requested menu is the formerly translated menu.
                $menu_id_map = $this->get_runtime_menu_id_map($current_lang);
                if (isset($menu_id_map[$requested_menu_id][$current_lang])) {
                    $mapped_id = intval($menu_id_map[$requested_menu_id][$current_lang]);
                    if ($mapped_id > 0) {
                        $args['menu'] = $mapped_id;
                        return $args;
                    }
                }
            }
        }
        
        return $args;
    }
    
    /**
     * Runtime filter: swap menu item URLs to point to the correct translated post
     * for the current language. This handles cases where translations were created
     * after the menu was built.
     *
     * @param array $items Menu item objects.
     * @param object $args Menu args.
     * @return array
     */
    public function filter_menu_item_urls($items, $args) {
        if (!$this->is_active()) {
            return $items;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        foreach ($items as &$item) {
            // Only process post_type items (pages, posts, custom post types)
            if ($item->type !== 'post_type' || empty($item->object_id)) {
                continue;
            }
            
            $object_id = (int) $item->object_id;
            
            // Try to find the translation for the current language
            $translations = $this->get_post_translations($object_id);
            if (isset($translations[$current_lang]) && (int) $translations[$current_lang] !== $object_id) {
                $translated_id = (int) $translations[$current_lang];
                $item->object_id = $translated_id;
                $item->url = get_permalink($translated_id);
                // Swap label to translated post title
                $translated_title = get_the_title($translated_id);
                if ($translated_title) {
                    $item->title = $translated_title;
                }
            } elseif (!isset($translations[$current_lang])) {
                // No translation exists — still fix the URL to include language prefix
                $item->url = get_permalink($object_id);
            }
        }
        
        return $items;
    }

    /**
     * Build a source-menu => translated-menu map for the current request, including
     * reverse entries inferred from saved translations. This keeps Elementor/Nav Menu
     * widgets correct after switching the site default language without rewriting the
     * stored menu metadata.
     *
     * @param string $target_lang Current frontend language.
     * @return array
     */
    private function get_runtime_menu_id_map($target_lang) {
        $menu_id_map = get_option('aitrfoel_menu_id_map', []);
        $menu_id_map = is_array($menu_id_map) ? $menu_id_map : [];

        $menu_assignments = get_option('aitrfoel_menu_assignments', []);
        if (is_array($menu_assignments) && !empty($menu_assignments)) {
            $nav_locations = get_nav_menu_locations();
            foreach ($nav_locations as $loc => $loc_menu_id) {
                $loc_menu_id = (int) $loc_menu_id;
                if ($loc_menu_id <= 0 || empty($menu_assignments[$loc]) || !is_array($menu_assignments[$loc])) {
                    continue;
                }
                foreach ($menu_assignments[$loc] as $lang => $translated_menu_id) {
                    $translated_menu_id = (int) $translated_menu_id;
                    if ($translated_menu_id <= 0) {
                        continue;
                    }
                    if (!isset($menu_id_map[$loc_menu_id])) {
                        $menu_id_map[$loc_menu_id] = [];
                    }
                    $menu_id_map[$loc_menu_id][$lang] = $translated_menu_id;
                }
            }
        }

        $language_codes = [];
        foreach ($this->get_stored_languages() as $lang) {
            if (is_array($lang) && !empty($lang['code'])) {
                $language_codes[] = (string) $lang['code'];
            } elseif (is_object($lang) && !empty($lang->slug)) {
                $language_codes[] = (string) $lang->slug;
            } elseif (is_object($lang) && !empty($lang->code)) {
                $language_codes[] = (string) $lang->code;
            }
        }

        $runtime_map = $menu_id_map;
        foreach ($menu_id_map as $source_menu_id => $lang_map) {
            $source_menu_id = (int) $source_menu_id;
            if ($source_menu_id <= 0 || !is_array($lang_map)) {
                continue;
            }

            $mapped_langs = array_map('strval', array_keys($lang_map));
            $source_langs = array_values(array_diff($language_codes, $mapped_langs));
            $reverse_langs = count($source_langs) === 1
                ? $source_langs
                : (in_array($target_lang, $source_langs, true) ? [$target_lang] : []);

            foreach ($lang_map as $lang => $translated_menu_id) {
                $translated_menu_id = (int) $translated_menu_id;
                if ($translated_menu_id <= 0) {
                    continue;
                }
                foreach ($reverse_langs as $source_lang) {
                    if (!isset($runtime_map[$translated_menu_id])) {
                        $runtime_map[$translated_menu_id] = [];
                    }
                    $runtime_map[$translated_menu_id][$source_lang] = $source_menu_id;
                }
            }
        }

        return $runtime_map;
    }

    /**
     * Remap a single post/product ID to its translation in the current language.
     * Returns the original ID when no translation exists or when on the default language.
     *
     * @param int $post_id
     * @return int
     */
    public function remap_id_to_current_language($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return $post_id;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $post_id;
        }

        $translations = $this->get_post_translations($post_id);
        if (isset($translations[$current_lang]) && get_post_status($translations[$current_lang]) === 'publish') {
            return (int) $translations[$current_lang];
        }

        return $post_id;
    }

    /**
     * Localize WooCommerce / Elementor "Products" widget queries.
     *
     * Two cases:
     *  1. Manual selection — the widget stores default-language product IDs in
     *     post__in / include. We remap each to its current-language translation.
     *  2. Category / generic queries — product categories are shared across
     *     languages, so we add a tax_query clause restricting results to the
     *     current language. This prevents default-language products (which are
     *     usually the oldest and sort first) from showing on translated pages.
     *
     * @param array  $query_args
     * @param array  $atts
     * @param string $type
     * @return array
     */
    public function filter_products_widget_query($query_args, $atts = [], $type = '') {
        if (!$this->is_active() || !is_array($query_args)) {
            return $query_args;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $query_args;
        }

        // Case 1: explicit product IDs (manual selection).
        $has_manual = false;
        foreach (['post__in', 'include'] as $key) {
            if (!empty($query_args[$key]) && is_array($query_args[$key])) {
                $has_manual = true;
                $query_args[$key] = array_map([$this, 'remap_id_to_current_language'], $query_args[$key]);
            }
        }

        // Case 2: category / generic query — scope to the current language.
        // (Skip when a manual ID list is present; that's already been remapped.)
        if (!$has_manual) {
            // Mirror archive/shop behavior: when product fallback is enabled,
            // also include default-language products so untranslated products
            // still appear. filter_products_widget_results() then remaps each
            // returned ID to its translation and de-duplicates, so a source +
            // its translation collapse to the single current-language product.
            $terms = [$current_lang];
            if (class_exists('WooCommerce') && get_option('aitrfoel_woo_product_fallback', true)) {
                $terms[] = $default_lang;
            }

            $lang_clause = [
                'taxonomy' => self::TAXONOMY,
                'field'    => 'slug',
                'terms'    => $terms,
                'operator' => 'IN',
            ];

            // Nest any existing tax_query as a single group AND'd with the language
            // clause. This is correct regardless of the existing query's relation
            // (e.g. an inner OR between categories stays intact and is still
            // constrained to the current language).
            $existing_tax_query = (!empty($query_args['tax_query']) && is_array($query_args['tax_query']))
                ? $query_args['tax_query']
                : [];

            if (empty($existing_tax_query)) {
                $query_args['tax_query'] = [$lang_clause];
            } else {
                $query_args['tax_query'] = [
                    'relation' => 'AND',
                    $lang_clause,
                    $existing_tax_query,
                ];
            }
        }

        return $query_args;
    }

    /**
     * Belt-and-suspenders remap of returned product IDs to the current language.
     * Covers query paths where tax_query / post__in remapping couldn't apply
     * (e.g. cached or pre-resolved ID lists).
     *
     * @param object $results WC results object exposing ->ids
     * @param mixed  $context
     * @return object
     */
    public function filter_products_widget_results($results, $context = null) {
        if (!$this->is_active() || !is_object($results) || empty($results->ids) || !is_array($results->ids)) {
            return $results;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $results;
        }

        $remapped = array_map([$this, 'remap_id_to_current_language'], $results->ids);
        $deduped  = array_values(array_unique($remapped));

        // Keep ->total consistent with the (possibly smaller) id list so the
        // widget's pagination/count doesn't reference dropped duplicates.
        if (isset($results->total) && count($deduped) !== count($results->ids)) {
            $removed = count($results->ids) - count($deduped);
            $results->total = max(0, (int) $results->total - $removed);
        }

        $results->ids = $deduped;

        return $results;
    }

    /**
     * Scope Elementor Pro's native Products / Loop Grid widget query to the
     * current language. Elementor Pro builds its own WP_Query via the
     * `elementor/query/query_args` filter, bypassing
     * `woocommerce_shortcode_products_query`. This method adds the same
     * language tax_query clause used by filter_products_widget_query so all
     * Elementor query widgets that target `product` post type return only the
     * current-language products.
     *
     * @param array $query_args
     * @param mixed $widget  Elementor widget instance (unused).
     * @return array
     */
    public function filter_elementor_query_lang($query_args, $widget = null) {
        if (!$this->is_active() || !is_array($query_args)) {
            return $query_args;
        }

        // Only apply to product queries.
        $post_types = isset($query_args['post_type']) ? (array) $query_args['post_type'] : [];
        if (!in_array('product', $post_types, true)) {
            return $query_args;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $query_args;
        }

        $terms = [$current_lang];
        if (get_option('aitrfoel_woo_product_fallback', true)) {
            $terms[] = $default_lang;
        }

        $lang_clause = [
            'taxonomy' => self::TAXONOMY,
            'field'    => 'slug',
            'terms'    => $terms,
            'operator' => 'IN',
        ];

        $existing = (!empty($query_args['tax_query']) && is_array($query_args['tax_query']))
            ? $query_args['tax_query'] : [];

        $query_args['tax_query'] = empty($existing)
            ? [$lang_clause]
            : ['relation' => 'AND', $lang_clause, $existing];

        return $query_args;
    }

    /**
     * Resolve an absolute internal URL (optionally language-prefixed) to its
     * underlying post ID, ignoring the language prefix.
     *
     * @param string $url
     * @return int Post ID, or 0 if not resolvable.
     */
    private function resolve_internal_url_to_post($url) {
        $raw_home = trailingslashit(get_option('home'));
        if (strpos($url, $raw_home) !== 0) {
            return 0; // External or relative — leave alone.
        }

        $relative = ltrim(substr($url, strlen($raw_home)), '/');
        $segments = $relative === '' ? [] : explode('/', $relative);

        // Strip a leading language-code segment if present.
        if (!empty($segments)) {
            $lang_codes = wp_list_pluck($this->get_stored_languages(), 'code');
            if (in_array($segments[0], $lang_codes, true)) {
                array_shift($segments);
            }
        }

        $clean = $raw_home . implode('/', $segments);

        // Resolve against the unfiltered home URL so our own prefix filter
        // doesn't interfere with url_to_postid(). Only restore the filter if it
        // was actually registered (it isn't in admin/preview contexts), so we
        // never accidentally add it where it wasn't.
        $had_home_filter = (false !== has_filter('home_url', [$this, 'filter_home_url']));
        if ($had_home_filter) {
            remove_filter('home_url', [$this, 'filter_home_url'], 10);
        }
        $post_id = url_to_postid($clean);
        if ($had_home_filter) {
            add_filter('home_url', [$this, 'filter_home_url'], 10, 4);
        }

        return (int) $post_id;
    }

    /**
     * Localize absolute internal links in rendered content to the current
     * language's translated target. Only rewrites links whose source post has a
     * translation in the current language; everything else is left untouched.
     *
     * @param string $content
     * @return string
     */
    public function localize_internal_links($content) {
        if (!$this->is_active() || !is_string($content) || $content === '') {
            return $content;
        }
        if (!get_option('aitrfoel_localize_internal_links', true)) {
            return $content;
        }

        // Run for any resolved language — NOT only non-default. When the site default
        // has been switched to a translation language, the default-language pages are
        // themselves translations whose embedded/inherited links still point at the
        // original-language pages, so they need localizing too. The rewrite is
        // idempotent (a link to a current-language post resolves to itself), so this is
        // safe on single-default sites as well. Bail only when there's a single language.
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || count($this->get_stored_languages()) < 2) {
            return $content;
        }

        $raw_home = rtrim(get_option('home'), '/');
        if ($raw_home === '' || strpos($content, $raw_home) === false) {
            return $content;
        }

        static $cache = [];
        $self = $this;

        // Protect language-switcher blocks and other explicitly marked regions
        // from link rewriting. The switcher contains intentional cross-language
        // hrefs (e.g. /fr/page/, /de/page/) that must not be redirected to the
        // current language.
        $stash = [];
        if (strpos($content, '<!-- aitrfoel-no-localize -->') !== false) {
            $content = preg_replace_callback(
                '/<!-- aitrfoel-no-localize -->[\s\S]*?<!-- \/aitrfoel-no-localize -->/U',
                function ($m) use (&$stash) {
                    $key = "\x00NL_" . count($stash) . "\x00";
                    $stash[] = $m[0];
                    return $key;
                },
                $content
            );
        }

        $result = preg_replace_callback(
            '/href=("|\')(' . preg_quote($raw_home, '/') . '[^"\'#?]*)((?:[?#][^"\']*)?)\1/i',
            function ($m) use (&$cache, $current_lang, $self) {
                $base = $m[2];
                $suffix = $m[3]; // preserve query string / fragment

                if (!array_key_exists($base, $cache)) {
                    $new = null; // null => leave the original link untouched
                    $post_id = $self->resolve_internal_url_to_post_public($base);
                    if ($post_id) {
                        $translations = $self->get_post_translations($post_id);
                        if (isset($translations[$current_lang])
                            && get_post_status($translations[$current_lang]) === 'publish') {
                            $new = get_permalink((int) $translations[$current_lang]);
                            $extra_path = $self->get_url_extra_path_public($base, $post_id);
                            if ($extra_path !== '') {
                                $new = rtrim($new, '/') . $extra_path;
                            }
                        }
                    }
                    $cache[$base] = $new;
                }

                if ($cache[$base] === null) {
                    return $m[0]; // unchanged
                }

                return 'href=' . $m[1] . $cache[$base] . $suffix . $m[1];
            },
            $content
        );

        // Also localize ROOT-RELATIVE internal links (href="/path") — the common case
        // that absolute-URL matching misses, and a frequent cause of "only some links
        // got rerouted". Protocol-relative (//cdn), admin and asset paths are skipped.
        $result = preg_replace_callback(
            '/href=("|\')(\/(?!\/)[^"\'#?]*)((?:[?#][^"\']*)?)\1/i',
            function ($m) use (&$cache, $current_lang, $self, $raw_home) {
                $path   = $m[2];
                $suffix = $m[3];
                if (preg_match('#^/(wp-admin|wp-content|wp-includes|wp-json)/#i', $path)) {
                    return $m[0];
                }
                $ckey = "\x00REL\x00" . $path;
                if (!array_key_exists($ckey, $cache)) {
                    $new     = null;
                    $post_id = $self->resolve_internal_url_to_post_public($raw_home . $path);
                    if ($post_id) {
                        $translations = $self->get_post_translations($post_id);
                        if (isset($translations[$current_lang])
                            && get_post_status($translations[$current_lang]) === 'publish') {
                            $new = get_permalink((int) $translations[$current_lang]);
                            $absolute_url = $raw_home . ltrim($path, '/');
                            $extra_path = $self->get_url_extra_path_public($absolute_url, $post_id);
                            if ($extra_path !== '') {
                                $new = rtrim($new, '/') . $extra_path;
                            }
                        }
                    }
                    $cache[$ckey] = $new;
                }
                if ($cache[$ckey] === null) {
                    return $m[0];
                }
                return 'href=' . $m[1] . $cache[$ckey] . $suffix . $m[1];
            },
            $result ?? $content
        );

        // Restore protected regions.
        if (!empty($stash)) {
            foreach ($stash as $i => $original) {
                $result = str_replace("\x00NL_{$i}\x00", $original, $result ?? $content);
            }
        }

        return $result ?? $content;
    }

    /**
     * Current language, with fallbacks for hooks that run before our
     * `parse_request` language detection. Used by render-time filters
     * (cart/checkout URLs, cart-item links) so they localize correctly even
     * when called during:
     *   - WooCommerce add-to-cart processing on `wp_loaded` (fires before
     *     parse_request, so REQUEST_URI is the only reliable signal).
     *   - Unprefixed AJAX requests (mini-cart fragments) where REQUEST_URI has
     *     no language prefix but the referer/cookie does.
     *
     * @return string
     */
    private function current_language_resolved() {
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            // 1) Direct REQUEST_URI inspection — handles `wp_loaded` callers
            //    (WooCommerce add-to-cart redirect) before parse_request runs.
            if (!empty($_SERVER['REQUEST_URI'])) {
                $req_path = wp_parse_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH) ?: '';
                $path_lang = $this->get_language_from_path($req_path);
                if ($path_lang) {
                    return $path_lang;
                }
            }
            // 2) AJAX referer/cookie fallback for unprefixed cart-fragment requests.
            $ajax_lang = $this->detect_ajax_language();
            if ($ajax_lang) {
                return $ajax_lang;
            }
        }
        return $current_lang;
    }

    /**
     * Return the permalink of the current-language translation of a WooCommerce
     * page (cart/checkout), or the original URL if no swap applies.
     *
     * @param string $url            Original page URL.
     * @param string $page_id_option Option name holding the page ID.
     * @return string
     */
    private function localized_wc_page_url($url, $page_id_option) {
        if (!$this->is_active()) {
            return $url;
        }
        $current_lang = $this->current_language_resolved();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $url;
        }

        // Read the real configured page ID without our pre_option swap interfering.
        remove_filter('pre_option_' . $page_id_option, [$this, 'filter_translated_page_option'], 10);
        $page_id = (int) get_option($page_id_option);
        add_filter('pre_option_' . $page_id_option, [$this, 'filter_translated_page_option'], 10, 2);

        if ($page_id <= 0) {
            return $url;
        }

        $translations = $this->get_post_translations($page_id);
        if (isset($translations[$current_lang])) {
            $translated_id = (int) $translations[$current_lang];
            if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
                $translated_url = get_permalink($translated_id);
                if ($translated_url) {
                    return $translated_url;
                }
            }
        }

        return $url;
    }

    /**
     * Localize the WooCommerce cart URL to the current language.
     *
     * @param string $url
     * @return string
     */
    public function filter_wc_cart_url($url) {
        return $this->localized_wc_page_url($url, 'woocommerce_cart_page_id');
    }

    /**
     * Localize the WooCommerce checkout URL to the current language.
     *
     * @param string $url
     * @return string
     */
    public function filter_wc_checkout_url($url) {
        return $this->localized_wc_page_url($url, 'woocommerce_checkout_page_id');
    }

    /**
     * Resolve the current-language product ID for a cart item, or 0 if no swap
     * is needed (default language, already current language, or no translation).
     *
     * @param array $cart_item
     * @return int
     */
    private function cart_item_translated_id($cart_item) {
        if (!$this->is_active() || empty($cart_item['product_id'])) {
            return 0;
        }
        $current_lang = $this->current_language_resolved();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return 0;
        }
        $source_id = (int) $cart_item['product_id'];
        if ($this->get_post_language($source_id) === $current_lang) {
            return 0;
        }
        // Resolve directly with $current_lang (which may come from the AJAX
        // fallback), rather than remap_id_to_current_language() which reads the
        // request-level current language.
        $translations = $this->get_post_translations($source_id);
        if (isset($translations[$current_lang])
            && get_post_status($translations[$current_lang]) === 'publish') {
            $translated_id = (int) $translations[$current_lang];
            return ($translated_id !== $source_id) ? $translated_id : 0;
        }
        return 0;
    }

    /**
     * Point a cart item's link to the current-language translation.
     *
     * @param string $permalink
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_permalink($permalink, $cart_item, $cart_item_key = '') {
        if (empty($permalink)) {
            return $permalink; // Non-visible product; leave as-is.
        }
        $translated_id = $this->cart_item_translated_id($cart_item);
        if ($translated_id) {
            $translated_permalink = get_permalink($translated_id);
            if ($translated_permalink) {
                return $translated_permalink;
            }
        }
        return $permalink;
    }

    /**
     * Show the current-language product name for a cart item. Preserves an
     * existing link wrapper if the name was passed as HTML.
     *
     * @param string $name
     * @param array  $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function filter_cart_item_name($name, $cart_item, $cart_item_key = '') {
        $translated_id = $this->cart_item_translated_id($cart_item);
        if (!$translated_id) {
            return $name;
        }
        $translated_title = get_the_title($translated_id);
        if (!$translated_title) {
            return $name;
        }
        // If the name is an <a>…</a> link, replace only the link text.
        if (stripos($name, '<a') !== false) {
            return preg_replace('/(<a\b[^>]*>).*?(<\/a>)/is', '$1' . esc_html($translated_title) . '$2', $name, 1);
        }
        return esc_html($translated_title);
    }

    /**
     * Localize a product loop item at render time. Fires on `the_post` for every
     * loop iteration (Elementor Products/Loop Grid, related, up-sells, cross-sells,
     * shortcodes, theme loops). When the iterated post is a product that is not in
     * the current language but has a published translation, swap the post/product
     * globals so all loop output (title, link, price, short description, add-to-cart)
     * renders in the current language. Routing-independent: works even when the
     * widget builds its query without passing through the WooCommerce shortcode
     * query filter.
     *
     * @param \WP_Post $post
     * @return void
     */
    public function localize_loop_product($post) {
        if (is_admin() || !$this->is_active()) {
            return;
        }
        if (!is_object($post) || empty($post->ID) || get_post_type($post) !== 'product') {
            return;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return;
        }

        // Already in the current language — nothing to swap.
        if ($this->get_post_language($post->ID) === $current_lang) {
            return;
        }

        $translations = $this->get_post_translations($post->ID);
        if (empty($translations[$current_lang])) {
            return;
        }
        $translated_id = (int) $translations[$current_lang];
        if ($translated_id === (int) $post->ID || get_post_status($translated_id) !== 'publish') {
            return;
        }

        $translated_post = get_post($translated_id);
        if (!$translated_post) {
            return;
        }

        // Swap the global post so post-based template tags (title, permalink,
        // excerpt) use the translation.
        $GLOBALS['post'] = $translated_post;
        setup_postdata($translated_post);

        // Swap the global product so WooCommerce loop output (price, add-to-cart)
        // uses the translation.
        if (function_exists('wc_setup_product_data')) {
            wc_setup_product_data($translated_post);
        } elseif (function_exists('wc_get_product')) {
            $GLOBALS['product'] = wc_get_product($translated_id);
        }
    }

    /**
     * Show the current-language short description for a product that is being
     * rendered on a translated page. The woocommerce_short_description filter
     * only passes the description string, so the product is resolved from the
     * global $product / $post context.
     *
     * Behavior:
     *  - If the rendered product is already in the current language, leave it.
     *  - If it's another language and a published current-language translation
     *    exists with a non-empty short description, return that instead.
     *  - Otherwise return the original unchanged (no worse than before).
     *
     * @param string $short_description
     * @return string
     */
    public function filter_product_short_description($short_description) {
        if (!$this->is_active()) {
            return $short_description;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        if (!$current_lang || $current_lang === $default_lang) {
            return $short_description;
        }

        // Resolve the product currently being rendered.
        $product_id = 0;
        if (isset($GLOBALS['product']) && $GLOBALS['product'] instanceof \WC_Product) {
            $product_id = $GLOBALS['product']->get_id();
        } elseif (isset($GLOBALS['post']) && isset($GLOBALS['post']->ID)) {
            $product_id = (int) $GLOBALS['post']->ID;
        }

        if ($product_id <= 0 || get_post_type($product_id) !== 'product') {
            return $short_description;
        }

        // Already in the current language — nothing to do.
        if ($this->get_post_language($product_id) === $current_lang) {
            return $short_description;
        }

        $translations = $this->get_post_translations($product_id);
        if (isset($translations[$current_lang])
            && get_post_status($translations[$current_lang]) === 'publish') {
            $translated = get_post($translations[$current_lang]);
            if ($translated && trim((string) $translated->post_excerpt) !== '') {
                return $translated->post_excerpt;
            }
        }

        return $short_description;
    }

    /**
     * Localize links inside individual Elementor widgets (Button, Icon, Image,
     * Call to Action, etc.) whose markup is rendered outside the_content and so
     * is not reached by the the_content / elementor frontend content filters.
     *
     * @param string $widget_content
     * @param mixed  $widget
     * @return string
     */
    public function localize_elementor_widget_links($widget_content, $widget = null) {
        if (!is_string($widget_content) || strpos($widget_content, 'href') === false) {
            return $widget_content;
        }
        return $this->localize_internal_links($widget_content);
    }

    /**
     * Public proxy so the link-localization closure can call the private resolver.
     *
     * @param string $url
     * @return int
     */
    public function resolve_internal_url_to_post_public($url) {
        return $this->resolve_internal_url_to_post($url);
    }

    /**
     * Helper to check if a URL path contains extra segments (like WooCommerce endpoints)
     * beyond the resolved post's actual slug/hierarchical path, and return them.
     *
     * @param string $url
     * @param int $post_id
     * @return string
     */
    public function get_url_extra_path_public($url, $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $post_path = '';
        if ($post->post_type === 'page') {
            $post_path = get_page_uri($post_id);
        } else {
            $post_path = $post->post_name;
        }

        $post_path = trim($post_path, '/');
        
        $raw_home = trailingslashit(get_option('home'));
        if (strpos($url, $raw_home) !== 0) {
            return '';
        }

        $relative = trim(substr($url, strlen($raw_home)), '/');
        $segments = explode('/', $relative);
        $lang_codes = array_column($this->get_stored_languages(), 'code');
        if (!empty($segments) && in_array($segments[0], $lang_codes, true)) {
            array_shift($segments);
        }
        $url_path_clean = trim(implode('/', $segments), '/');

        if ($url_path_clean !== '' && $post_path !== '') {
            if ($url_path_clean === $post_path) {
                return '';
            }
            if (strpos($url_path_clean, $post_path . '/') === 0) {
                $extra = substr($url_path_clean, strlen($post_path));
                $extra_path = '/' . ltrim($extra, '/');
                
                // Preserve trailing slash if present in the original URL
                $url_no_query = explode('?', $url)[0];
                if (substr($url_no_query, -1) === '/') {
                    $extra_path = trailingslashit($extra_path);
                }
                
                return $extra_path;
            }
        }

        return '';
    }

    /**
     * Build the custom string-translation lookup map. Memoized.
     * Returns: [ source_string => [ lang_code => translation ] ].
     *
     * @return array
     */
    public function get_string_translation_map() {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        $entries = get_option('aitrfoel_string_translations', []);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (empty($entry['source']) || empty($entry['translations']) || !is_array($entry['translations'])) {
                    continue;
                }
                $map[trim((string) $entry['source'])] = $entry['translations'];
            }
        }
        return $map;
    }

    /**
     * Register the custom-string-translation filters on `wp`, once the current
     * language is known. No-op on the default language or when no strings exist.
     */
    public function register_string_translations() {
        if (is_admin() || !$this->is_active()) {
            return;
        }
        $map = $this->get_string_translation_map();
        if (empty($map)) {
            return;
        }
        $lang    = $this->current_language_resolved();
        $default = $this->get_default_language();
        if (!$lang || $lang === $default) {
            return;
        }
        $this->st_lang = $lang;

        // Catches strings rendered through __()/_e() that have no translation
        // pack in this language (e.g. third-party plugin UI).
        add_filter('gettext', [$this, 'filter_gettext_string'], 99, 3);
        add_filter('gettext_with_context', [$this, 'filter_gettext_string_ctx'], 99, 4);

        // Catches admin-configured option values not rendered via gettext.
        if (class_exists('WooCommerce')) {
            add_filter('woocommerce_gateway_title', [$this, 'filter_string_value'], 99);
            add_filter('woocommerce_gateway_description', [$this, 'filter_string_value'], 99);
            // Translate the RAW privacy option (before WooCommerce replaces the
            // [privacy_policy] placeholder with a link) so the saved source matches.
            add_filter('option_woocommerce_checkout_privacy_policy_text', [$this, 'filter_string_value'], 99);
            add_filter('option_woocommerce_registration_privacy_policy_text', [$this, 'filter_string_value'], 99);
        }
    }

    /**
     * Replace a displayed string with its current-language override, if defined.
     *
     * @param string $value
     * @return string
     */
    public function translate_custom_string($value) {
        if (!is_string($value) || $value === '' || empty($this->st_lang)) {
            return $value;
        }
        $map = $this->get_string_translation_map();
        $key = trim($value);
        if ($key !== '' && isset($map[$key][$this->st_lang]) && $map[$key][$this->st_lang] !== '') {
            // Preserve any surrounding whitespace from the original value.
            return str_replace($key, $map[$key][$this->st_lang], $value);
        }
        return $value;
    }

    /**
     * Filter callback for plain option/string values.
     * @param string $value
     * @return string
     */
    public function filter_string_value($value) {
        return $this->translate_custom_string($value);
    }

    /**
     * Filter callback for gettext (matches on the displayed/translated string).
     * @param string $translated
     * @return string
     */
    public function filter_gettext_string($translated, $text = '', $domain = 'default') {
        // Match on the displayed (translated) string first; if that doesn't match,
        // also try the original source msgid — but only return a value that actually
        // matched a stored override, never the bare msgid.
        $r = $this->translate_custom_string($translated);
        if ($r !== $translated) {
            return $r;
        }
        if ($text !== '' && $text !== $translated) {
            $r2 = $this->translate_custom_string($text);
            if ($r2 !== $text) {
                return $r2;
            }
        }
        return $translated;
    }

    /**
     * Filter callback for gettext_with_context.
     * @param string $translated
     * @return string
     */
    public function filter_gettext_string_ctx($translated, $text = '', $context = '', $domain = 'default') {
        return $this->translate_custom_string($translated);
    }

    /**
     * Warn admins when Elementor's "Element Caching" experiment is active while
     * our language localization is in use. Element caching keys rendered widget
     * HTML by element settings (not by URL/language), so a header/footer/template
     * element can be cached once and served with its original-language links and
     * products across every translated page. Elementor provides no per-language
     * cache-key filter, so the safe mitigation is to disable Element Caching —
     * the same guidance other multilingual/dynamic plugins give.
     */
    public function maybe_warn_elementor_element_cache() {
        if (!current_user_can('manage_options') || !$this->is_active()) {
            return;
        }

        // Only relevant when one of the render-time localization features is on.
        $links_on = (bool) get_option('aitrfoel_localize_internal_links', true);
        $woo_on   = class_exists('WooCommerce') && (bool) get_option('aitrfoel_woocommerce_support', true);
        if (!$links_on && !$woo_on) {
            return;
        }

        // Detect the active Elementor element-caching experiment.
        if (!class_exists('\Elementor\Plugin') || !isset(\Elementor\Plugin::$instance->experiments)) {
            return;
        }
        $experiments = \Elementor\Plugin::$instance->experiments;
        if (!method_exists($experiments, 'is_feature_active')
            || !$experiments->is_feature_active('e_element_cache')) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p><strong>'
            . esc_html__('Meep AI Translator:', 'ai-translator-for-elementor-and-polylang') . '</strong> '
            . esc_html__('Elementor "Element Caching" is enabled. It can cache widget output (links and products) in a single language and show it on translated pages, bypassing Meep\'s localization. We recommend turning it off at Elementor → Settings → Features while using translated pages.', 'ai-translator-for-elementor-and-polylang')
            . ' <a href="' . esc_url(admin_url('admin.php?page=elementor-settings#tab-experiments')) . '">'
            . esc_html__('Open Elementor Features', 'ai-translator-for-elementor-and-polylang') . '</a>'
            . '</p></div>';
    }

    /**
     * Redirect untranslated pages to their default language version.
     * If a user visits /de/services/ but no German translation exists,
     * redirect to /services/ (the default language page).
     */
    public function maybe_redirect_untranslated_page() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        
        if (!$this->is_active() || !is_singular()) {
            return;
        }

        if ($this->is_path_excluded_from_routing()) {
            return;
        }

        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();

        if ($current_lang === $default_lang) {
            return;
        }

        $post_id = get_queried_object_id();
        if (!$post_id) {
            return;
        }
        
        // Check if the current post is in the current language AND is published.
        // A draft/pending translation should also redirect to the default language,
        // otherwise anonymous visitors get a 404 or a caching redirect loop.
        $post_lang   = $this->get_post_language($post_id);
        $post_status = get_post_status($post_id);
        
        // Logged-in editors/admins must never be redirected away from their own draft translations.
        // They need to be able to preview newly created drafts via ?p=ID, ?page_id=ID, or pretty permalink.
        if (current_user_can('edit_posts')) {
            return;
        }
        
        // Only redirect if the post truly belongs to a different language or is not published.
        $needs_redirect = ( $post_lang !== $current_lang ) ||
                          ( $post_lang === $current_lang && $post_status !== 'publish' );
        
        if (!$needs_redirect) {
            return;
        }
        
        $default_lang_prefix = get_option('aitrfoel_default_lang_prefix', false);
        
        // For the front page: redirect directly to the default language home.
        // Using get_permalink() for front page returns home_url('/') which then
        // triggers another hop through maybe_redirect_default_lang_prefix — this
        // extra hop can interact with Cloudflare / host-level redirects and loop.
        if (is_front_page()) {
            $default_url = $default_lang_prefix
                ? home_url('/' . $default_lang . '/')
                : home_url('/');
        } else {
            // For regular pages/posts, redirect to the default-language version.
            // Temporarily remove our prefix filters so get_permalink returns the raw URL.
            remove_filter('page_link',      [$this, 'filter_page_permalink'], 10);
            remove_filter('post_link',      [$this, 'filter_permalink'],      10);
            remove_filter('post_type_link', [$this, 'filter_permalink'],      10);
            
            // Try to find the actual default-language post for this content.
            $translations    = $this->get_post_translations($post_id);
            $default_post_id = isset($translations[$default_lang]) ? (int) $translations[$default_lang] : $post_id;
            $default_url     = get_permalink($default_post_id) ?: home_url('/');
            
            add_filter('post_link',      [$this, 'filter_permalink'],      10, 2);
            add_filter('page_link',      [$this, 'filter_page_permalink'], 10, 2);
            add_filter('post_type_link', [$this, 'filter_permalink'],      10, 2);
        }
        
        // Anti-loop guard: don't redirect if the target path equals the current path.
        $current_path = rtrim(wp_parse_url(home_url(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'))), PHP_URL_PATH) ?? '/', '/');
        $target_path  = rtrim(wp_parse_url($default_url, PHP_URL_PATH) ?? '/', '/');
        if ($current_path === $target_path) {
            return;
        }
        
        wp_safe_redirect($default_url, 302);
        exit;
    }
    
    // =========================================================================
    // PER-LANGUAGE WIDGET VISIBILITY
    // =========================================================================
    
    /**
     * Filter widget display: hide widget if it's restricted to languages
     * that don't include the current language.
     *
     * @param array     $instance Widget instance settings.
     * @param WP_Widget $widget   Widget object.
     * @param array     $args     Widget area arguments.
     * @return array|false False to prevent display.
     */
    public function filter_widget_by_language($instance, $widget, $args) {
        if (!$this->is_active() || empty($instance)) {
            return $instance;
        }
        
        $current_lang = $this->get_current_language();
        $default_lang = $this->get_default_language();
        
        // Check settings-based widget language assignments (from plugin settings page)
        $widget_id = $widget->id; // e.g. "text-2"
        $widget_lang_settings = get_option('aitrfoel_widget_languages', []);
        if (!empty($widget_lang_settings[$widget_id])) {
            $langs = (array) $widget_lang_settings[$widget_id];
            if (!in_array($current_lang, $langs, true)) {
                return false; // Hide widget
            }
        }
        
        // Fallback: check per-widget instance setting (classic widgets form)
        if (!empty($instance['aitrfoel_languages'])) {
            $langs = (array) $instance['aitrfoel_languages'];
            if (!in_array($current_lang, $langs, true)) {
                return false; // Hide widget
            }
        }
        
        // Swap translated content if viewing a non-default language
        if ($current_lang !== $default_lang) {
            $translations = get_option('aitrfoel_widget_translations', []);
            if (!empty($translations[$widget_id][$current_lang])) {
                $tr = $translations[$widget_id][$current_lang];
                // Swap block widget content
                if (isset($tr['content']) && isset($instance['content'])) {
                    $instance['content'] = $tr['content'];
                }
                // Swap widget title
                if (isset($tr['title']) && isset($instance['title'])) {
                    $instance['title'] = $tr['title'];
                }
                // Swap nav_menu widget to translated menu
                if (isset($tr['nav_menu']) && isset($instance['nav_menu'])) {
                    $instance['nav_menu'] = $tr['nav_menu'];
                }
            }
        }
        
        return $instance;
    }
    
    /**
     * Add language visibility controls to every widget's admin form.
     *
     * @param WP_Widget $widget   Widget object.
     * @param null      $return   Unused.
     * @param array     $instance Current widget instance settings.
     */
    public function widget_language_form($widget, $return, $instance) {
        if (!$this->is_active()) {
            return;
        }
        
        $languages = $this->get_available_languages();
        if (empty($languages)) {
            return;
        }
        
        $selected = isset($instance['aitrfoel_languages']) ? (array) $instance['aitrfoel_languages'] : [];
        $field_name = $widget->get_field_name('aitrfoel_languages');
        ?>
        <div style="margin: 8px 0; padding: 8px; background: #f0f6fc; border-left: 3px solid #2271b1; border-radius: 3px;">
            <p style="margin: 0 0 6px; font-weight: 600; font-size: 12px;">
                <?php esc_html_e('Show in Languages:', 'ai-translator-for-elementor-and-polylang'); ?>
            </p>
            <label style="display: block; margin-bottom: 4px;">
                <input type="checkbox" class="aitrfoel-widget-lang-all"
                       <?php checked(empty($selected)); ?>
                       onchange="var c=this.closest('div').querySelectorAll('.aitrfoel-widget-lang-cb');c.forEach(function(x){x.checked=false;});" />
                <?php esc_html_e('All languages', 'ai-translator-for-elementor-and-polylang'); ?>
            </label>
            <?php foreach ($languages as $lang): ?>
            <label style="display: inline-block; margin-right: 10px; margin-bottom: 4px;">
                <input type="checkbox" class="aitrfoel-widget-lang-cb"
                       name="<?php echo esc_attr($field_name); ?>[]"
                       value="<?php echo esc_attr($lang->code); ?>"
                       <?php checked(in_array($lang->code, $selected, true)); ?>
                       onchange="this.closest('div').querySelector('.aitrfoel-widget-lang-all').checked=false;" />
                <?php echo esc_html(strtoupper($lang->code) . ' — ' . $lang->name); ?>
            </label>
            <?php endforeach; ?>
            <p class="description" style="margin: 4px 0 0; font-size: 11px;">
                <?php esc_html_e('Select specific languages to show this widget only for those languages, or leave "All" checked.', 'ai-translator-for-elementor-and-polylang'); ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Save the language visibility setting when widget is updated.
     *
     * @param array     $instance     New widget instance.
     * @param array     $new_instance Values from the form.
     * @param array     $old_instance Previously saved values.
     * @param WP_Widget $widget       Widget object.
     * @return array
     */
    public function widget_language_save($instance, $new_instance, $old_instance, $widget) {
        if (isset($new_instance['aitrfoel_languages']) && is_array($new_instance['aitrfoel_languages'])) {
            $valid_codes = array_keys($this->get_available_languages());
            $instance['aitrfoel_languages'] = array_values(array_intersect($new_instance['aitrfoel_languages'], $valid_codes));
        } else {
            $instance['aitrfoel_languages'] = []; // All languages
        }
        return $instance;
    }

    /**
     * Swap the id of the Elementor Pro [elementor-template id="X"] shortcode
     * to the current-language translation BEFORE Elementor renders it.
     *
     * Fires on pre_do_shortcode_tag. Returning a non-false value short-circuits
     * the default handler with our own output — so we look up the translated
     * template id, mutate the attributes, and invoke Elementor's registered
     * shortcode callback ourselves with the swapped id.
     *
     * This is the most direct fix for [elementor-template] embedded inside
     * page/widget content: it avoids any document caching that Elementor may
     * key on the original template id and complements the existing
     * elementor/frontend/builder_content_data fallback.
     *
     * @param false|string $result Short-circuit value (false to run shortcode normally).
     * @param string       $tag    Shortcode tag name.
     * @param array|string $attr   Shortcode attributes (may be empty string when none).
     * @param array        $m      Regex match array.
     * @return false|string False to fall through, or rendered shortcode output.
     */
    public function swap_elementor_template_shortcode_id($result, $tag, $attr, $m) {
        $supported_tags = ['elementor-template', 'contact-form-7', 'sureforms', 'srfm_form', 'wpforms'];
        if ($result !== false || !in_array($tag, $supported_tags, true)) {
            return $result;
        }
        if (!$this->is_active()) {
            return $result;
        }
        if ($tag === 'elementor-template' && $this->should_skip_elementor_template_swap()) {
            return $result;
        }
        if (!is_array($attr)) {
            return $result;
        }

        $id_key = isset($attr['id']) ? 'id' : (isset($attr['form_id']) ? 'form_id' : (isset($attr['formId']) ? 'formId' : ''));
        if (!$id_key || empty($attr[$id_key]) || !is_numeric($attr[$id_key])) {
            return $result;
        }

        // Run for any resolved language — NOT only non-default.
        $current_lang = $this->get_current_language();
        if (!$current_lang || count($this->get_stored_languages()) < 2) {
            return $result;
        }

        $original_id = (int) $attr[$id_key];
        if ($original_id <= 0) {
            return $result;
        }

        // Don't act on an item that is itself a translation.
        $src = (int) get_post_meta($original_id, '_aitrfoel_source_page', true);
        if ($src && $src !== $original_id) {
            return $result;
        }

        // Re-entrancy / cycle guard.
        if (in_array($original_id, self::$elementor_shortcode_render_stack, true)
            || count(self::$elementor_shortcode_render_stack) >= 20) {
            return $result;
        }

        $translated_id = 0;
        if ($tag === 'elementor-template') {
            $translated_id = $this->get_translated_runtime_elementor_template_id($original_id, $current_lang);
        } else {
            // Form post types: contact-form-7, sureforms, wpforms
            $translations = $this->get_post_translations($original_id);
            if (!empty($translations[$current_lang])) {
                $tid = (int) $translations[$current_lang];
                if ($tid > 0 && $tid !== $original_id && get_post_status($tid) === 'publish') {
                    $translated_id = $tid;
                }
            }
        }

        if ($translated_id <= 0 || $translated_id === $original_id) {
            return $result;
        }
        if (in_array($translated_id, self::$elementor_shortcode_render_stack, true)) {
            return $result;
        }

        global $shortcode_tags;
        if (!isset($shortcode_tags[$tag]) || !is_callable($shortcode_tags[$tag])) {
            return $result;
        }

        $attr[$id_key] = (string) $translated_id;
        self::$elementor_shortcode_render_stack[] = $original_id;
        self::$elementor_shortcode_render_stack[] = $translated_id;
        try {
            $rendered = call_user_func($shortcode_tags[$tag], $attr, null, $tag);
        } finally {
            array_pop(self::$elementor_shortcode_render_stack);
            array_pop(self::$elementor_shortcode_render_stack);
        }
        return $rendered;
    }

    /**
     * Runtime filter for Gutenberg form selector blocks (SureForms, CF7, WPForms):
     * Swaps the embedded form ID to the current language's translated form ID before the block renders.
     */
    public function swap_form_block_id_at_runtime($parsed_block, $source_block) {
        if (empty($parsed_block['blockName']) || empty($parsed_block['attrs']) || !is_array($parsed_block['attrs'])) {
            return $parsed_block;
        }
        $supported_blocks = [
            'srfm/form',
            'sureforms/form',
            'contact-form-7/contact-form-selector',
            'contact-form-7/form',
            'wpforms/form-selector',
        ];
        if (!in_array($parsed_block['blockName'], $supported_blocks, true) || !$this->is_active()) {
            return $parsed_block;
        }
        $current_lang = $this->get_current_language();
        if (!$current_lang || count($this->get_stored_languages()) < 2) {
            return $parsed_block;
        }
        $id_key = isset($parsed_block['attrs']['id']) ? 'id' : (isset($parsed_block['attrs']['formId']) ? 'formId' : '');
        if (!$id_key || empty($parsed_block['attrs'][$id_key]) || !is_numeric($parsed_block['attrs'][$id_key])) {
            return $parsed_block;
        }
        $original_id = (int) $parsed_block['attrs'][$id_key];
        if ($original_id <= 0) {
            return $parsed_block;
        }
        $src = (int) get_post_meta($original_id, '_aitrfoel_source_page', true);
        if ($src && $src !== $original_id) {
            return $parsed_block;
        }
        $translations = $this->get_post_translations($original_id);
        if (!empty($translations[$current_lang])) {
            $tid = (int) $translations[$current_lang];
            if ($tid > 0 && $tid !== $original_id && get_post_status($tid) === 'publish') {
                $parsed_block['attrs'][$id_key] = is_string($parsed_block['attrs'][$id_key]) ? (string) $tid : $tid;
            }
        }
        return $parsed_block;
    }

    /**
     * Swap the Elementor template ID to its translation for the current language.
     *
     * This is the primary hook for Elementor Pro Theme Builder template swapping.
     * It mirrors the approach used by "Connect Polylang for Elementor":
     *
     *   1. Translated templates have NO _elementor_conditions (enforced by
     *      elementor_conditions_empty_on_translations() below).
     *   2. Elementor resolves its candidates using only the default-language template's
     *      conditions — translated templates are invisible to the resolver.
     *   3. Once Elementor has selected the default-language template ID, this filter
     *      swaps it to the translated version for the current language.
     *
     * Called via elementor/theme/get_location_templates/template_id filter.
     *
     * @param int $post_id The template post ID Elementor selected via conditions.
     * @return int The translated template post ID, or the original if no translation exists.
     */
    public function swap_elementor_template_id_to_translation($post_id) {
        if (!$this->is_active() || $this->should_skip_elementor_template_swap()) {
            return $post_id;
        }

        // Run for any resolved language — NOT only non-default.
        $current_lang = $this->get_current_language();
        if (!$current_lang || count($this->get_stored_languages()) < 2) {
            return $post_id;
        }

        // Don't act on a template that is itself a translation.
        $src = (int) get_post_meta((int) $post_id, '_aitrfoel_source_page', true);
        if ($src && $src !== (int) $post_id) {
            return $post_id;
        }

        $translations = $this->get_post_translations((int) $post_id);
        if (!isset($translations[$current_lang])) {
            return $post_id; // No translation — fall back to original.
        }

        $translated_id = (int) $translations[$current_lang];
        if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
            return $translated_id;
        }

        return $post_id;
    }

    /**
     * Return empty conditions for translated Elementor templates.
     *
     * Hooked to get_post_metadata (always, admin + frontend).
     *
     * Elementor Pro queries _elementor_conditions when building its candidates list.
     * If a translated template has conditions it competes with the original, causing
     * the wrong-language template to appear. By returning an empty array here,
     * translated templates are invisible to the conditions resolver.
     * The actual swap happens via swap_elementor_template_id_to_translation().
     *
     * This mirrors elementor_conditions_empty_on_translations() in Connect Polylang for Elementor.
     *
     * @param mixed  $null     null (pass-through) or override value.
     * @param int    $post_id  Post being queried.
     * @param string $meta_key Meta key being read.
     * @return mixed null (pass-through) or empty array.
     */

    /**
     * Serve the translated Elementor data of a JetEngine listing item when it's
     * rendered on a non-default language, so static text inside the listing template
     * (labels like "KLANT", "Bezoek website") shows in the visitor's language.
     *
     * JetEngine renders a listing by reading the listing post's `_elementor_data`;
     * by returning the translation's data here we localize it without depending on
     * JetEngine-internal hooks, and it covers JetSmartFilters AJAX re-renders too.
     *
     * Hooked on get_post_metadata (front end / front-end AJAX only).
     *
     * @param mixed  $null
     * @param int    $post_id
     * @param string $meta_key
     * @return mixed
     */
    /**
     * Merge the admin-configured extra template post types into a filter list.
     * Hooked on both aitrfoel_template_post_types and aitrfoel_metadata_swap_template_types.
     *
     * @param array $types
     * @return array
     */
    public function merge_extra_template_post_types($types) {
        $types = (array) $types;
        $extra = get_option('aitrfoel_extra_template_post_types', '');
        if (is_string($extra) && $extra !== '') {
            foreach (preg_split('/[\s,]+/', $extra) as $slug) {
                $slug = sanitize_key(trim($slug));
                if ($slug !== '') {
                    $types[] = $slug;
                }
            }
        }
        return array_values(array_unique(array_filter($types)));
    }

    /**
     * Swap the template id JetThemeCore is about to render for a location to the
     * current-language translation. Hooked on jet-theme-core/location/render/template-id.
     * Covers single / header / footer / archive theme-builder templates.
     *
     * @param int $template_id
     * @return int
     */
    public function swap_jetthemecore_template_id($template_id) {
        if (is_admin() && !wp_doing_ajax()) {
            return $template_id;
        }
        if (!$this->is_active() || $this->should_skip_elementor_template_swap()) {
            return $template_id;
        }
        $template_id = (int) $template_id;
        if ($template_id <= 0) {
            return $template_id;
        }

        // Run for any resolved language (not only non-default) so a switched default
        // language still swaps the original header/footer/single template to its
        // translation. Idempotent for single-default sites.
        $current = $this->current_language_resolved();
        if (!$current || count($this->get_stored_languages()) < 2) {
            return $template_id;
        }

        // Don't act on a template that is itself a translation.
        $src = (int) get_post_meta($template_id, '_aitrfoel_source_page', true);
        if ($src && $src !== $template_id) {
            return $template_id;
        }

        $translations = $this->get_post_translations($template_id);
        if (!isset($translations[$current])) {
            return $template_id;
        }
        $tid = (int) $translations[$current];
        if ($tid <= 0 || $tid === $template_id || get_post_status($tid) !== 'publish') {
            return $template_id;
        }

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('JetThemeCore template swapped: #%d → #%d (%s)', $template_id, $tid, $current),
                'info', $template_id
            );
        }

        return $tid;
    }

    public function swap_jetengine_listing_data($null, $post_id, $meta_key) {
        if ('_elementor_data' !== $meta_key) {
            return $null;
        }
        static $depth = 0;
        if ($depth > 0) {
            return $null; // re-entrancy guard (we read the translation's own data below)
        }
        if (!$this->is_active() || $this->should_skip_elementor_template_swap()) {
            return $null;
        }

        // Only act on JetEngine listing templates (and any types added via filter,
        // but never elementor_library — that has its own swap path).
        $types = apply_filters('aitrfoel_metadata_swap_template_types', ['jet-engine']);
        if (!in_array(get_post_type($post_id), (array) $types, true)) {
            return $null;
        }

        // Run for any resolved language so a switched default still swaps the listing
        // item's data to its translation (idempotent for single-default sites).
        $current = $this->current_language_resolved();
        if (!$current || count($this->get_stored_languages()) < 2) {
            return $null;
        }

        // Don't act on a post that is itself a translation.
        $src = (int) get_post_meta($post_id, '_aitrfoel_source_page', true);
        if ($src && $src !== (int) $post_id) {
            return $null;
        }

        $translations = $this->get_post_translations($post_id);
        if (!isset($translations[$current])) {
            return $null;
        }
        $tid = (int) $translations[$current];
        if ($tid <= 0 || $tid === (int) $post_id || get_post_status($tid) !== 'publish') {
            return $null;
        }

        $depth++;
        $data = get_post_meta($tid, '_elementor_data', true);
        $depth--;

        if (empty($data)) {
            return $null;
        }

        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('Template data swapped via get_post_metadata: #%d (%s) → #%d (%s)', $post_id, get_post_type($post_id), $tid, $current),
                'info', $post_id
            );
        }

        // Returning a single-element array makes get_post_meta(..., true) return $data.
        return [$data];
    }

    public function elementor_conditions_empty_on_translations($null, $post_id, $meta_key) {
        if ('_elementor_conditions' !== $meta_key) {
            return $null;
        }
        if (!$this->is_active()) {
            return $null;
        }
        // Only intercept elementor_library posts.
        if (get_post_type($post_id) !== 'elementor_library') {
            return $null;
        }

        // Empty conditions if this is a translated template (not the source template).
        // A template is a translation if it has _aitrfoel_source_page pointing to another post.
        $source = (int) get_post_meta($post_id, '_aitrfoel_source_page', true);
        if ($source > 0 && $source !== (int) $post_id) {
            return [[]]; // Polylang convention
        }

        return $null;
    }

    /**
     * Swap Elementor template data when rendering a template in a non-default language.
     * This is a fallback for the builder_content_data path (e.g. Template widget, shortcodes).
     * Called via elementor/frontend/builder_content_data filter.
     *
     * @param array $data    Elementor data array.
     * @param int   $post_id Post ID of the template being rendered.
     * @return array
     */
    public function filter_elementor_template_data($data, $post_id) {
        if (!$this->is_active() || empty($data)) {
            return $data;
        }

        if ($this->should_skip_elementor_template_swap()) {
            return $data;
        }

        // Run for any resolved language — NOT only non-default. After a default-language
        // switch, default-language pages render the original (source) header/footer/
        // template, which still needs swapping to its translation. The swap is
        // idempotent (a current-language template resolves to itself), so this stays
        // safe on single-default sites. Bail only when there's just one language.
        $current_lang = $this->get_current_language();
        if (!$current_lang || count($this->get_stored_languages()) < 2) {
            return $data;
        }

        // Swap for Elementor library templates AND builder template post types that
        // render via this filter — JetEngine listings and JetThemeCore single/header/
        // footer templates (post type 'jet-theme-core'), plus any added via the filter.
        $post_type  = get_post_type($post_id);
        $swap_types = array_merge(
            ['elementor_library', 'jet-theme-core', 'elementor-hf'],
            (array) apply_filters('aitrfoel_metadata_swap_template_types', ['jet-engine'])
        );
        if (!in_array($post_type, $swap_types, true)) {
            return $data;
        }

        $menu_id_map = $this->get_runtime_menu_id_map($current_lang);

        // Try loading a fully translated template first
        $translations = $this->get_post_translations($post_id);
        if (isset($translations[$current_lang])) {
            $translated_id = (int) $translations[$current_lang];
            if ($translated_id > 0 && get_post_status($translated_id) === 'publish') {
                $translated_data_json = get_post_meta($translated_id, '_elementor_data', true);
                if (!empty($translated_data_json)) {
                    $translated_data = json_decode($translated_data_json, true);
                    if (is_array($translated_data) && !empty($translated_data)) {
                        if (class_exists('AITRFOEL_Logger')) {
                            AITRFOEL_Logger::log(
                                sprintf('Template data swapped via builder_content_data: #%d (%s) → #%d (%s)', $post_id, $post_type, $translated_id, $current_lang),
                                'info', $post_id
                            );
                        }
                        if (!empty($menu_id_map)) {
                            $this->swap_menu_ids_in_elementor_data_recursive($translated_data, $menu_id_map, $current_lang);
                        }
                        $this->swap_nested_elementor_template_refs_recursive($translated_data, $current_lang);

                        // Enqueue translated template CSS stylesheet
                        if (class_exists('\Elementor\Core\Files\CSS\Post')) {
                            try {
                                $css_file = \Elementor\Core\Files\CSS\Post::create($translated_id);
                                if ($css_file) {
                                    $css_file->enqueue();
                                }
                            } catch (\Throwable $t) {}
                        }

                        return $translated_data;
                    }
                }
            }
        }

        // Fallback: no translated template available — dynamically swap menu IDs
        // in the original template data so Nav Menu widgets show the correct language menu
        $menu_id_map = get_option('aitrfoel_menu_id_map', []);
        $menu_assignments = get_option('aitrfoel_menu_assignments', []);

        // Build a combined source→translated map from both location assignments and direct map
        $combined_map = $this->get_runtime_menu_id_map($current_lang);
        if (!empty($menu_assignments) && is_array($menu_assignments)) {
            $nav_locations = get_nav_menu_locations();
            foreach ($nav_locations as $loc => $loc_menu_id) {
                $loc_menu_id = (int) $loc_menu_id;
                if ($loc_menu_id > 0 && isset($menu_assignments[$loc][$current_lang])) {
                    $combined_map[$loc_menu_id][$current_lang] = (int) $menu_assignments[$loc][$current_lang];
                }
            }
        }

        if (!empty($combined_map)) {
            $this->swap_menu_ids_in_elementor_data_recursive($data, $combined_map, $current_lang);
        }

        $this->swap_nested_elementor_template_refs_recursive($data, $current_lang);

        return $data;
    }

    /**
     * Recursively swap nav-menu widget menu IDs in Elementor data at runtime.
     *
     * @param array  &$data       Elementor elements array (modified in place).
     * @param array  $menu_id_map Source menu ID => [lang => translated menu ID].
     * @param string $target_lang Target language code.
     */
    private function swap_menu_ids_in_elementor_data_recursive(&$data, $menu_id_map, $target_lang) {
        foreach ($data as &$element) {
            if (!is_array($element)) continue;

            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';
            if ($widget_type === 'nav-menu' && isset($element['settings']['menu'])) {
                $source_menu_id = (int) $element['settings']['menu'];
                if ($source_menu_id > 0 && isset($menu_id_map[$source_menu_id][$target_lang])) {
                    $element['settings']['menu'] = (string) $menu_id_map[$source_menu_id][$target_lang];
                }
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->swap_menu_ids_in_elementor_data_recursive($element['elements'], $menu_id_map, $target_lang);
            }
        }
        unset($element);
    }

    /**
     * Recursively swap nested Elementor reusable template references.
     *
     * Covers common reusable-element patterns such as the Template widget
     * (`settings.template_id`) and Global Widget references (`templateID`).
     *
     * @param array  &$data       Elementor elements array (modified in place).
     * @param string $target_lang Target language code.
     */
    private function swap_nested_elementor_template_refs_recursive(&$data, $target_lang) {
        foreach ($data as &$element) {
            if (!is_array($element)) continue;

            $widget_type = isset($element['widgetType']) ? $element['widgetType'] : '';

            if ($widget_type === 'template' && isset($element['settings']['template_id'])) {
                $translated_id = $this->get_translated_runtime_elementor_template_id((int) $element['settings']['template_id'], $target_lang);
                if ($translated_id > 0) {
                    $element['settings']['template_id'] = (string) $translated_id;
                }
            }

            if (isset($element['templateID'])) {
                $translated_id = $this->get_translated_runtime_elementor_template_id((int) $element['templateID'], $target_lang);
                if ($translated_id > 0) {
                    $element['templateID'] = $translated_id;
                }
            }

            if (isset($element['elements']) && is_array($element['elements'])) {
                $this->swap_nested_elementor_template_refs_recursive($element['elements'], $target_lang);
            }
        }
        unset($element);
    }

    /**
     * Resolve a translated reusable Elementor template for runtime rendering.
     *
     * @param int    $template_id Source template ID.
     * @param string $target_lang Target language code.
     * @return int
     */
    private function get_translated_runtime_elementor_template_id($template_id, $target_lang) {
        if ($template_id <= 0 || get_post_type($template_id) !== 'elementor_library') {
            return 0;
        }

        $translations = $this->get_post_translations($template_id);
        if (!isset($translations[$target_lang])) {
            return 0;
        }

        $translated_id = (int) $translations[$target_lang];
        if ($translated_id <= 0 || get_post_status($translated_id) !== 'publish') {
            return 0;
        }

        return $translated_id;
    }

    /**
     * Do not swap template content when previewing or editing the template itself.
     *
     * @return bool
     */
    private function should_skip_elementor_template_swap() {
        if (is_singular('elementor_library')) {
            return true;
        }

        // Elementor editor loads as a non-admin frontend iframe with ?elementor in the URL.
        if (isset($_GET['elementor'])) {
            return true;
        }

        $preview_id = isset($_GET['elementor-preview']) ? (int) $_GET['elementor-preview'] : 0;
        if ($preview_id > 0 && get_post_type($preview_id) === 'elementor_library') {
            return true;
        }

        // Use Elementor's own API to detect editor / preview mode when available.
        if (class_exists('\\Elementor\\Plugin')) {
            $elementor = \Elementor\Plugin::instance();
            if (isset($elementor->editor) && method_exists($elementor->editor, 'is_edit_mode') && $elementor->editor->is_edit_mode()) {
                return true;
            }
            if (isset($elementor->preview) && method_exists($elementor->preview, 'is_preview_mode') && $elementor->preview->is_preview_mode()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register switcher JavaScript to be printed safely in the footer.
     * Prevents themes/builders from stripping script tags from header widgets.
     */
    public function register_switcher_javascript() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }
        add_action('wp_footer', [$this, 'output_switcher_javascript'], 99);
    }

    /**
     * Print the switcher dropdown interaction script in the footer.
     */
    public function output_switcher_javascript() {
        echo '<!-- aitrfoel-no-localize --><script id="aitrfoel-switcher-js">
(function(){
    document.querySelectorAll(".aitrfoel-switcher-dropdown-wrapper").forEach(function(w){
        var btn = w.querySelector(".aitrfoel-dropdown-trigger");
        var list = w.querySelector(".aitrfoel-dropdown-list");
        if(!btn||!list||btn.getAttribute("data-aitrfoel-bound")) return;
        btn.setAttribute("data-aitrfoel-bound","1");
        btn.addEventListener("click",function(e){
            e.stopPropagation();
            var open = list.style.display !== "none";
            list.style.display = open ? "none" : "block";
            btn.setAttribute("aria-expanded", open ? "false" : "true");
        });
    });
    if(!window.__aitrfoelSwitcherClose){
        window.__aitrfoelSwitcherClose = true;
        document.addEventListener("click",function(){
            document.querySelectorAll(".aitrfoel-dropdown-list").forEach(function(l){ l.style.display="none"; });
            document.querySelectorAll(".aitrfoel-dropdown-trigger").forEach(function(b){ b.setAttribute("aria-expanded","false"); });
        });
    }
})();
</script><!-- /aitrfoel-no-localize -->';
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('meep-translator/v1', '/translate-string', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_translate_string'],
            'permission_callback' => function () {
                return current_user_can('edit_posts');
            }
        ]);
    }

    /**
     * REST API callback to translate a string
     */
    public function rest_translate_string($request) {
        $params = $request->get_json_params();
        if (empty($params)) {
            $params = $request->get_default_params();
        }

        $text = isset($params['text']) ? sanitize_textarea_field($params['text']) : '';
        $target_lang = isset($params['target_lang']) ? sanitize_text_field($params['target_lang']) : '';
        $source_lang = isset($params['source_lang']) ? sanitize_text_field($params['source_lang']) : 'auto';

        if (empty($text) || empty($target_lang)) {
            return new WP_Error('missing_params', __('Missing required parameters', 'ai-translator-for-elementor-and-polylang'), ['status' => 400]);
        }

        // Translate the string using the API client
        if (!class_exists('AITRFOEL_OpenAI_API')) {
            return new WP_Error('api_missing', __('API client not loaded', 'ai-translator-for-elementor-and-polylang'), ['status' => 500]);
        }

        $api = AITRFOEL_OpenAI_API::get_instance();
        
        // Word limit check
        if (class_exists('AITRFOEL_Translation_Engine')) {
            $engine = AITRFOEL_Translation_Engine::get_instance();
            if (method_exists($engine, 'check_word_limit')) {
                // Count words
                $estimated_words = count(preg_split('/\s+/u', trim($text)));
                $check = $engine->check_word_limit($estimated_words);
                if (is_wp_error($check)) {
                    return $check;
                }
            }
        }

        // Call translate_text which handles glossary system prompt internally
        $translated = $api->translate_text($text, $target_lang, $source_lang);

        if (is_wp_error($translated)) {
            return new WP_Error('translation_failed', $translated->get_error_message(), ['status' => 500]);
        }

        return rest_ensure_response([
            'success' => true,
            'translated_text' => $translated
        ]);
    }
}

/**
 * Language Switcher WordPress Widget
 *
 * Allows users to add a language switcher to any widget area (sidebar, footer, etc.)
 * Only active when the built-in language system is enabled.
 */
class AITRFOEL_Language_Switcher_Widget extends WP_Widget {

    /**
     * Constructor
     */
    public function __construct() {
        parent::__construct(
            'aitrfoel_language_switcher',
            __('Meep Translator – Language Switcher', 'ai-translator-for-elementor-and-polylang'),
            [
                'description' => __('Display a language switcher for the built-in language system.', 'ai-translator-for-elementor-and-polylang'),
                'classname'   => 'aitrfoel-language-switcher-widget',
            ]
        );
    }

    /**
     * Front-end display
     *
     * @param array $args     Widget arguments.
     * @param array $instance Saved values from database.
     */
    public function widget($args, $instance) {
        if (!get_option('aitrfoel_switcher_enabled', true)) {
            return;
        }
        
        if (!class_exists('AITRFOEL_Builtin_Integration') || !AITRFOEL_Builtin_Integration::is_enabled()) {
            return;
        }

        $title = !empty($instance['title']) ? $instance['title'] : '';
        $style = !empty($instance['style']) ? $instance['style'] : 'list';

        echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar wrapper markup registered by the theme (standard widget output)

        if ($title) {
            echo $args['before_title'] . esc_html(apply_filters('widget_title', $title)) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar wrapper markup registered by the theme (standard widget output)
        }

        // Per-widget flag/name toggles, defaulting to the global switcher settings
        // when this widget instance hasn't set them explicitly.
        $show_flags = isset($instance['show_flags'])
            ? ((bool) $instance['show_flags'] ? 'true' : 'false')
            : (get_option('aitrfoel_switcher_show_flags', true) ? 'true' : 'false');
        $show_names = isset($instance['show_names'])
            ? ((bool) $instance['show_names'] ? 'true' : 'false')
            : (get_option('aitrfoel_switcher_show_names', true) ? 'true' : 'false');

        $builtin = AITRFOEL_Builtin_Integration::get_instance();
        echo $builtin->render_language_switcher_shortcode([ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language switcher HTML built from escaped parts (esc_url/esc_attr/esc_html)
            'style'      => $style, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language switcher HTML built from escaped parts (esc_url/esc_attr/esc_html)
            'show_flags' => $show_flags, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language switcher HTML built from escaped parts (esc_url/esc_attr/esc_html)
            'show_names' => $show_names, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- language switcher HTML built from escaped parts (esc_url/esc_attr/esc_html)
        ]);

        echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sidebar wrapper markup registered by the theme (standard widget output)
    }

    /**
     * Back-end widget form
     *
     * @param array $instance Previously saved values from database.
     */
    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : __('Languages', 'ai-translator-for-elementor-and-polylang');
        $style = !empty($instance['style']) ? $instance['style'] : 'list';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'ai-translator-for-elementor-and-polylang'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('style')); ?>"><?php esc_html_e('Display Style:', 'ai-translator-for-elementor-and-polylang'); ?></label>
            <select class="widefat" id="<?php echo esc_attr($this->get_field_id('style')); ?>" name="<?php echo esc_attr($this->get_field_name('style')); ?>">
                <option value="list" <?php selected($style, 'list'); ?>><?php esc_html_e('List', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="dropdown" <?php selected($style, 'dropdown'); ?>><?php esc_html_e('Dropdown', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="flags" <?php selected($style, 'flags'); ?>><?php esc_html_e('Flags / Codes', 'ai-translator-for-elementor-and-polylang'); ?></option>
                <option value="flags-text" <?php selected($style, 'flags-text'); ?>><?php esc_html_e('Flags + Text', 'ai-translator-for-elementor-and-polylang'); ?></option>
            </select>
        </p>
        <?php
        $inst_show_flags = isset($instance['show_flags']) ? (bool) $instance['show_flags'] : (bool) get_option('aitrfoel_switcher_show_flags', true);
        $inst_show_names = isset($instance['show_names']) ? (bool) $instance['show_names'] : (bool) get_option('aitrfoel_switcher_show_names', true);
        ?>
        <p>
            <input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_flags')); ?>" name="<?php echo esc_attr($this->get_field_name('show_flags')); ?>" value="1" <?php checked($inst_show_flags); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_flags')); ?>"><?php esc_html_e('Show flags', 'ai-translator-for-elementor-and-polylang'); ?></label>
        </p>
        <p>
            <input type="checkbox" id="<?php echo esc_attr($this->get_field_id('show_names')); ?>" name="<?php echo esc_attr($this->get_field_name('show_names')); ?>" value="1" <?php checked($inst_show_names); ?>>
            <label for="<?php echo esc_attr($this->get_field_id('show_names')); ?>"><?php esc_html_e('Show names', 'ai-translator-for-elementor-and-polylang'); ?></label>
        </p>
        <?php
    }

    /**
     * Sanitize widget form values as they are saved
     *
     * @param array $new_instance Values just sent to be saved.
     * @param array $old_instance Previously saved values from database.
     * @return array Updated safe values to be saved.
     */
    public function update($new_instance, $old_instance) {
        $instance = [];
        $instance['title'] = sanitize_text_field($new_instance['title'] ?? '');
        $instance['style'] = in_array($new_instance['style'] ?? '', ['list', 'dropdown', 'flags', 'flags-text'], true) ? $new_instance['style'] : 'list';
        $instance['show_flags'] = !empty($new_instance['show_flags']) ? '1' : '0';
        $instance['show_names'] = !empty($new_instance['show_names']) ? '1' : '0';
        return $instance;
    }
}
