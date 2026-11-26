<?php
/**
 * Background Sync Class
 * Automatically queues and processes translation updates in the background when content changes.
 */

if (!defined('ABSPATH')) {
    exit;
}

class AITRFOEL_Background_Sync {
    
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Only run background sync if the user enabled it (default: true)
        if (!get_option('aitrfoel_enable_background_sync', true)) {
            return;
        }

        // Hooks for content updates
        add_action('save_post', [$this, 'handle_save_post'], 20, 3);
        add_action('elementor/document/after_save', [$this, 'handle_elementor_save'], 20, 1);

        // Cron action callback
        add_action('aitrfoel_background_sync_page', [$this, 'process_background_sync'], 10, 2);
    }

    /**
     * Handle Gutenberg / Classic editor saves
     */
    public function handle_save_post($post_id, $post, $update) {
        // Skip revisions, autosaves, and non-updated saves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$update) return;
        if (wp_is_post_revision($post_id)) return;

        // Skip if not supported post type
        $public_types = get_post_types(['public' => true], 'names');
        $supported_types = array_unique(array_merge(['post', 'page', 'product', 'elementor_library'], array_values($public_types)));
        if (!in_array($post->post_type, $supported_types, true)) return;

        // Only sync from original default language post to its translations
        $manager = AITRFOEL_Multilingual_Manager::get_instance();
        $default_lang = $manager->get_default_language();
        $post_lang = $manager->get_post_language($post_id);

        if ($post_lang !== $default_lang) {
            return; // Skip if editing a translation directly
        }

        // Check if layout content hash changed
        $content = $post->post_content;
        $current_hash = md5($content);
        $stored_hash = get_post_meta($post_id, '_aitrfoel_source_content_hash', true);

        if ($current_hash === $stored_hash) {
            return; // Content didn't change
        }

        // Update hash
        update_post_meta($post_id, '_aitrfoel_source_content_hash', $current_hash);

        // Fetch target languages with translations
        $translations = $manager->get_post_translations($post_id);
        if (empty($translations)) {
            return;
        }

        foreach ($translations as $lang => $translated_post_id) {
            if (intval($translated_post_id) === intval($post_id)) {
                continue; // Skip original itself
            }

            // Schedule background sync event for each translation
            wp_schedule_single_event(time() + 5, 'aitrfoel_background_sync_page', [$post_id, $lang]);
        }
    }

    /**
     * Handle Elementor visual editor saves
     */
    public function handle_elementor_save($document) {
        if (!is_object($document) || !method_exists($document, 'get_main_id')) return;
        
        $post_id = $document->get_main_id();
        $post = get_post($post_id);
        if (!$post) return;

        $manager = AITRFOEL_Multilingual_Manager::get_instance();
        $default_lang = $manager->get_default_language();
        $post_lang = $manager->get_post_language($post_id);

        if ($post_lang !== $default_lang) {
            return;
        }

        // Elementor stores data in _elementor_data post meta
        $elementor_data = get_post_meta($post_id, '_elementor_data', true);
        if (empty($elementor_data)) return;

        $current_hash = md5($elementor_data);
        $stored_hash = get_post_meta($post_id, '_aitrfoel_source_elementor_hash', true);

        if ($current_hash === $stored_hash) {
            return;
        }

        update_post_meta($post_id, '_aitrfoel_source_elementor_hash', $current_hash);

        $translations = $manager->get_post_translations($post_id);
        if (empty($translations)) {
            return;
        }

        foreach ($translations as $lang => $translated_post_id) {
            if (intval($translated_post_id) === intval($post_id)) {
                continue;
            }

            wp_schedule_single_event(time() + 5, 'aitrfoel_background_sync_page', [$post_id, $lang]);
        }
    }

    /**
     * Process background translation update
     */
    public function process_background_sync($original_post_id, $target_lang) {
        if (class_exists('AITRFOEL_Logger')) {
            AITRFOEL_Logger::log(
                sprintf('🔄 Starting background translation auto-sync for Post #%d to language %s', $original_post_id, strtoupper($target_lang)),
                'info',
                $original_post_id
            );
        }

        if (!class_exists('AITRFOEL_Translation_Engine')) {
            return;
        }

        // Run translation engine on the page
        $engine = AITRFOEL_Translation_Engine::get_instance();
        $result = $engine->translate_page($original_post_id, $target_lang, 'auto');

        if (class_exists('AITRFOEL_Logger')) {
            if (is_wp_error($result)) {
                AITRFOEL_Logger::log(
                    sprintf('❌ Background sync failed for Post #%d to language %s. Error: %s', $original_post_id, strtoupper($target_lang), $result->get_error_message()),
                    'error',
                    $original_post_id
                );
            } else {
                AITRFOEL_Logger::log(
                    sprintf('✅ Background sync successfully completed for Post #%d to language %s', $original_post_id, strtoupper($target_lang)),
                    'info',
                    $original_post_id
                );
            }
        }
    }
}
