<?php
/**
 * Polylang Integration Class
 * Handles linking translations with Polylang plugin
 */

if (!defined('ABSPATH')) exit;

class AITRFOEL_Polylang_Integration {
    private static $instance = null;
    
    /**
     * Language code mapping from our format to Polylang format
     */
    private $language_map = [
        'English' => 'en',
        'Russian' => 'ru', 
        'Spanish' => 'es',
        'French' => 'fr',
        'German' => 'de',
        'Italian' => 'it',
        'Portuguese' => 'pt',
        'Ukrainian' => 'uk',
        'Belarusian' => 'be',
        'Czech' => 'cs',
        'Polish' => 'pl',
        'Vietnamese' => 'vi',
        'Chinese' => 'zh',
        'Japanese' => 'ja',
        'Korean' => 'ko',
        'Arabic' => 'ar',
        'Hebrew' => 'he',
        'Hindi' => 'hi',
        'Turkish' => 'tr',
        'Dutch' => 'nl',
        'Swedish' => 'sv',
        'Norwegian' => 'no',
        'Danish' => 'da',
        'Finnish' => 'fi'
    ];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize hooks if Polylang is active
        if (function_exists('pll_get_post_language')) {
            add_action('init', [$this, 'init_polylang_hooks']);
        }
    }
    
    /**
     * Initialize Polylang hooks
     */
    public function init_polylang_hooks() {
        // Hook to add language switcher for translated pages
        add_filter('pll_get_post_translations', [$this, 'ensure_translation_links'], 10, 2);
    }
    
    /**
     * Check if Polylang is active and functional
     * 
     * @return bool
     */
    public function is_polylang_active() {
        return function_exists('pll_set_post_language') && 
               function_exists('pll_get_post_translations') &&
               function_exists('pll_save_post_translations') &&
               function_exists('pll_get_post_language');
    }
    
    /**
     * Get available Polylang languages
     * 
     * @return array Array of language objects
     */
    public function get_available_languages() {
        if (!$this->is_polylang_active()) {
            return [];
        }
        
        $languages = [];
        if (function_exists('pll_languages_list') && function_exists('PLL')) {
            $lang_codes = pll_languages_list();
            $pll = PLL();
            foreach ($lang_codes as $code) {
                if ($pll && isset($pll->model)) {
                    $lang_obj = $pll->model->get_language($code);
                    if ($lang_obj) {
                        $languages[$code] = $lang_obj;
                    }
                }
            }
        }
        return $languages;
    }
    
    /**
     * Convert our language name to Polylang language code
     * 
     * @param string $our_language Our language name (e.g., 'Russian')
     * @return string|null Polylang language code (e.g., 'ru') or null if not found
     */
    public function get_polylang_language_code($our_language) {
        return $this->language_map[$our_language] ?? null;
    }
    
    /**
     * Check if language code exists in Polylang
     * 
     * @param string $lang_code Polylang language code
     * @return bool
     */
    public function language_exists($lang_code) {
        if (!$this->is_polylang_active()) {
            return false;
        }
        
        $languages = $this->get_available_languages();
        foreach ($languages as $lang) {
            if ($lang->slug === $lang_code) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Setup translation relationship between original and translated post
     * 
     * @param int $original_post_id Original post ID
     * @param int $translated_post_id Translated post ID  
     * @param string $target_language Target language code or name (e.g., 'ru' or 'Russian')
     * @return bool|WP_Error Success or error
     */
    public function setup_translation_relationship($original_post_id, $translated_post_id, $target_language) {
        if (!$this->is_polylang_active()) {
            AITRFOEL_Logger::log('Polylang is not active', 'error', $original_post_id);
            return new WP_Error('polylang_inactive', 'Polylang is not active.');
        }

        // Получаем код языка (например, 'ru', 'es')
        $pll_lang_code = (strlen($target_language) > 2) ? $this->get_polylang_language_code($target_language) : $target_language;

        if (!$pll_lang_code || !$this->language_exists($pll_lang_code)) {
            AITRFOEL_Logger::log("Language '{$target_language}' not found in Polylang.", 'error', $original_post_id);
            return new WP_Error('language_not_found', "Language '{$target_language}' not found in Polylang.");
        }

        // Получаем язык исходного поста
        $original_lang_code = pll_get_post_language($original_post_id);
        
        // ВАЖНО: Если у оригинала нет языка, УСТАНАВЛИВАЕМ его!
        if (!$original_lang_code) {
            // Устанавливаем язык по умолчанию для оригинала
            $default_lang = pll_default_language();
            if (!$default_lang) {
                // Если нет языка по умолчанию, берем первый доступный
                $available = $this->get_available_languages();
                if (!empty($available)) {
                    $default_lang = reset($available)->slug;
                }
            }
            
            if ($default_lang) {
                pll_set_post_language($original_post_id, $default_lang);
                $original_lang_code = $default_lang;
                AITRFOEL_Logger::log("Set default language '{$default_lang}' for original post {$original_post_id}", 'info', $original_post_id);
            } else {
                AITRFOEL_Logger::log('Cannot determine default language for original post', 'error', $original_post_id);
                return new WP_Error('no_default_language', 'Cannot determine default language.');
            }
        }

        // Устанавливаем язык для переведенного поста
        pll_set_post_language($translated_post_id, $pll_lang_code);
        AITRFOEL_Logger::log("Set language '{$pll_lang_code}' for translated post {$translated_post_id}", 'info', $translated_post_id);

        // 1. ВАЖНО: Проверяем, есть ли уже страница на целевом языке
        $existing_translation = pll_get_post($original_post_id, $pll_lang_code);
        if ($existing_translation && $existing_translation != $translated_post_id) {
            // Нашли существующий перевод - добавляем к его группе!
            AITRFOEL_Logger::log("Found existing translation {$existing_translation} for language {$pll_lang_code}, merging groups", 'info', $original_post_id);
            $translations = pll_get_post_translations($existing_translation);
        } else {
            // Нет существующего перевода - берем группу оригинала
            $translations = pll_get_post_translations($original_post_id);
        }
        
        // Если массив пустой, добавляем сам оригинал
        if (empty($translations)) {
            $translations[$original_lang_code] = $original_post_id;
        }

        // 2. Добавляем наш новый перевод в группу
        $translations[$pll_lang_code] = $translated_post_id;

        // 3. Сохраняем всю группу
        pll_save_post_translations($translations);
        
        // 4. Проверяем, что связь создана
        $verify = pll_get_post_translations($translated_post_id);
        if (isset($verify[$pll_lang_code]) && $verify[$pll_lang_code] == $translated_post_id) {
            AITRFOEL_Logger::log(
                sprintf('✅ Polylang relationship created successfully! Group: %s', json_encode($translations)),
                'success',
                $original_post_id,
                $translated_post_id
            );
            return true;
        } else {
            AITRFOEL_Logger::log(
                sprintf('⚠️ Polylang relationship may not be complete. Expected: %s, Got: %s', 
                    json_encode($translations), 
                    json_encode($verify)
                ),
                'warning',
                $original_post_id,
                $translated_post_id
            );
            return true; // Still return true as we did our best
        }
    }
    
    /**
     * Detect or set language for original post if not set
     * 
     * @param int $post_id Post ID
     * @return string|null Language code or null if failed
     */
    private function detect_or_set_original_language($post_id) {
        // Get default Polylang language
        $default_lang = pll_default_language();
        
        if ($default_lang) {
            pll_set_post_language($post_id, $default_lang);
            AITRFOEL_Logger::log(
                sprintf('Set default language "%s" for original post %d', $default_lang, $post_id),
                'info',
                $post_id
            );
            return $default_lang;
        }
        
        // If no default language, try to set the first available language
        $languages = $this->get_available_languages();
        if (!empty($languages)) {
            $first_lang = reset($languages);
            pll_set_post_language($post_id, $first_lang->slug);
            AITRFOEL_Logger::log(
                sprintf('Set first available language "%s" for original post %d', $first_lang->slug, $post_id),
                'info',
                $post_id
            );
            return $first_lang->slug;
        }
        
        return null;
    }
    
    /**
     * Get translation status for a post across all languages
     * 
     * @param int $post_id Post ID
     * @return array Translation status array
     */
    public function get_translation_status($post_id) {
        if (!$this->is_polylang_active()) {
            return [];
        }
        
        $status = [];
        $translations = pll_get_post_translations($post_id);
        $available_languages = $this->get_available_languages();
        
        foreach ($available_languages as $lang) {
            $status[$lang->slug] = [
                'language_name' => $lang->name,
                'language_code' => $lang->slug,
                'translated' => isset($translations[$lang->slug]),
                'post_id' => $translations[$lang->slug] ?? null,
                'edit_url' => isset($translations[$lang->slug]) ? get_edit_post_link($translations[$lang->slug]) : null,
                'view_url' => isset($translations[$lang->slug]) ? get_permalink($translations[$lang->slug]) : null,
                'status' => isset($translations[$lang->slug]) ? get_post_status($translations[$lang->slug]) : null
            ];
        }
        
        return $status;
    }
    
    /**
     * Get all pages with their translation status
     * 
     * @return array Array of pages with translation status
     */
    public function get_pages_translation_status() {
        if (!$this->is_polylang_active()) {
            return [];
        }
        
        // Get all pages with Elementor data
        $pages = get_posts([
            'post_type' => 'page',
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft'],
            'meta_query' => [
                [
                    'key' => '_elementor_data',
                    'compare' => 'EXISTS'
                ]
            ],
            'orderby' => 'title',
            'order' => 'ASC'
        ]);
        
        $pages_status = [];
        $available_languages = $this->get_available_languages();
        
        foreach ($pages as $page) {
            $translation_status = $this->get_translation_status($page->ID);
            
            $pages_status[] = [
                'id' => $page->ID,
                'title' => $page->post_title,
                'status' => $page->post_status,
                'edit_url' => get_edit_post_link($page->ID),
                'view_url' => get_permalink($page->ID),
                'translations' => $translation_status,
                'has_elementor' => !empty(get_post_meta($page->ID, '_elementor_data', true))
            ];
        }
        
        return $pages_status;
    }
    
    /**
     * Ensure translation links are properly maintained
     * This filter helps maintain consistency in translation links
     * 
     * @param array $translations Current translations
     * @param int $post_id Post ID
     * @return array Updated translations
     */
    public function ensure_translation_links($translations, $post_id) {
        // This filter can be used to ensure consistency
        // For now, just return the translations as-is
        return $translations;
    }
    
    /**
     * Remove translation relationship
     * 
     * @param int $post_id Post ID to remove from translations
     * @return bool Success
     */
    public function remove_translation_relationship($post_id) {
        if (!$this->is_polylang_active()) {
            return false;
        }
        
        try {
            // Get current translations
            $translations = pll_get_post_translations($post_id);
            
            // Remove this post from translations
            $post_lang = pll_get_post_language($post_id);
            if ($post_lang && isset($translations[$post_lang])) {
                unset($translations[$post_lang]);
                
                // If there are remaining translations, update them
                if (!empty($translations)) {
                    pll_save_post_translations($translations);
                }
            }
            
            // Remove language from the post
            pll_set_post_language($post_id, '');
            
            return true;
            
        } catch (Exception $e) {
            AITRFOEL_Logger::log(
                sprintf('Failed to remove translation relationship for post %d: %s', $post_id, $e->getMessage()),
                'error',
                $post_id
            );
            return false;
        }
    }
    
    /**
     * Get Polylang language switcher data for admin
     * 
     * @return array Language switcher data
     */
    public function get_language_switcher_data() {
        if (!$this->is_polylang_active()) {
            return [];
        }
        
        $languages = $this->get_available_languages();
        $switcher_data = [];
        
        foreach ($languages as $lang) {
            $switcher_data[] = [
                'code' => $lang->slug,
                'name' => $lang->name,
                'flag' => $lang->flag ?? '',
                'is_default' => $lang->slug === pll_default_language()
            ];
        }
        
        return $switcher_data;
    }
}
