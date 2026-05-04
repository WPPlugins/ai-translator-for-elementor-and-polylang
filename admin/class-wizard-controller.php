<?php
/**
 * Wizard Controller — AJAX handlers for the onboarding wizard
 *
 * Extracted from class-admin-menu.php (Phase 1 tech-debt split).
 * Owns three wizard AJAX actions:
 *   - aitrfoel_setup_builtin_languages
 *   - aitrfoel_complete_wizard
 *   - aitrfoel_wizard_demo_translate
 *
 * Smaller, focused class keeps class-admin-menu.php closer to a single
 * responsibility (rendering only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AITRFOEL_Wizard_Controller {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_aitrfoel_setup_builtin_languages', [ $this, 'handle_setup_builtin_languages' ] );
		add_action( 'wp_ajax_aitrfoel_complete_wizard',          [ $this, 'handle_complete_wizard' ] );
		add_action( 'wp_ajax_aitrfoel_wizard_demo_translate',    [ $this, 'handle_wizard_demo_translate' ] );
	}

	// -------------------------------------------------------------------------
	// AJAX: configure languages and enable built-in system
	// -------------------------------------------------------------------------

	public function handle_setup_builtin_languages() {
		if ( ! check_ajax_referer( 'aitrfoel_builtin_setup', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$default_lang_code = sanitize_text_field( $_POST['default_language'] ?? 'en' );
		$default_lang_name = sanitize_text_field( $_POST['default_language_name'] ?? 'English' );
		// Don't use sanitize_text_field on JSON — it can strip valid characters
		$target_langs_json = wp_unslash( $_POST['target_languages'] ?? '[]' );
		$target_langs      = json_decode( $target_langs_json, true );

		if ( empty( $target_langs ) || ! is_array( $target_langs ) ) {
			wp_send_json_error( __( 'Please select at least one target language.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		// Load the built-in integration class if not already loaded
		if ( ! class_exists( 'AITRFOEL_Builtin_Integration' ) ) {
			require_once AITRFOEL_PLUGIN_DIR . 'includes/class-multilingual-interface.php';
			require_once AITRFOEL_PLUGIN_DIR . 'includes/class-builtin-integration.php';
		}

		// Enable the built-in system
		$builtin = AITRFOEL_Builtin_Integration::get_instance();
		$builtin->enable();

		// Create DB table
		$builtin->create_table();

		// Reset rewrite rules version so they get flushed on the next normal page load
		// (during AJAX, the init hook has already fired so add_rewrite_rules() won't run)
		delete_option( 'aitrfoel_rewrite_rules_version' );

		// Add default language
		$builtin->add_language( $default_lang_code, $default_lang_name, $default_lang_name, true );

		// Add target languages
		$added = 0;
		foreach ( $target_langs as $lang ) {
			if ( ! empty( $lang['code'] ) && ! empty( $lang['name'] ) ) {
				$lang_code = sanitize_text_field( $lang['code'] );
				$lang_name = sanitize_text_field( $lang['name'] );
				if ( $builtin->add_language( $lang_code, $lang_name, $lang_name, false ) ) {
					$added++;
				}
			}
		}

		// Import Polylang translation relationships if Polylang data exists
		$this->import_polylang_translations( $builtin, $default_lang_code );

		// Clear cached dependency status so the dashboard re-evaluates
		delete_option( 'aitrfoel_dependencies_status' );

		// Flush rewrite rules for language URL prefixes
		flush_rewrite_rules();

		wp_send_json_success( [
			'message'         => sprintf(
				__( 'Languages configured! %d languages set up. Redirecting to dashboard...', 'ai-translator-for-elementor-and-polylang' ),
				$added + 1
			),
			'languages_count' => $added + 1,
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: mark wizard complete (Polylang / WPML path)
	// -------------------------------------------------------------------------

	/**
	 * Mark wizard setup complete when Polylang/WPML is already active.
	 * Returns front-page data for the optional demo translation step.
	 */
	public function handle_complete_wizard() {
		if ( ! check_ajax_referer( 'aitrfoel_builtin_setup', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		// Clear cached dependency status so dashboard reflects current multilingual state.
		delete_option( 'aitrfoel_dependencies_status' );

		// Resolve front page for the optional demo translation step.
		$front_page_id = (int) get_option( 'page_on_front' );
		if ( $front_page_id <= 0 ) {
			// Fallback: most recently published page
			$pages         = get_posts( [
				'post_type'   => 'page',
				'post_status' => 'publish',
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			] );
			$front_page_id = ! empty( $pages ) ? $pages[0]->ID : 0;
		}

		// Estimate word count so JS can decide whether to run the demo.
		$demo_word_count = 0;
		if ( $front_page_id > 0 && class_exists( 'AITRFOEL_Translation_Engine' ) ) {
			$content         = get_post_field( 'post_content', $front_page_id );
			$demo_word_count = AITRFOEL_Translation_Engine::count_words( wp_strip_all_tags( $content ) );
		}

		wp_send_json_success( [
			'message'         => __( 'Setup complete. Ready to translate.', 'ai-translator-for-elementor-and-polylang' ),
			'demo_post_id'    => $front_page_id,
			'demo_word_count' => $demo_word_count,
			'demo_post_title' => $front_page_id > 0 ? get_the_title( $front_page_id ) : '',
		] );
	}

	// -------------------------------------------------------------------------
	// AJAX: demo translate the front page during onboarding
	// -------------------------------------------------------------------------

	/**
	 * Translate the front page as a wizard demo (fires once during onboarding).
	 * Capped at 500 words — silently skips if the page is too large.
	 */
	public function handle_wizard_demo_translate() {
		if ( ! check_ajax_referer( 'aitrfoel_builtin_setup', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$post_id     = (int) ( $_POST['post_id']     ?? 0 );
		$target_lang = sanitize_text_field( $_POST['target_lang'] ?? '' );
		$source_lang = sanitize_text_field( $_POST['source_lang'] ?? 'auto' );

		if ( $post_id <= 0 || empty( $target_lang ) ) {
			wp_send_json_error( __( 'Missing parameters.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! class_exists( 'AITRFOEL_Translation_Engine' ) ) {
			wp_send_json_error( __( 'Translation engine not available.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		// Word-count guard: skip silently if page is too large for a quick demo.
		$content         = get_post_field( 'post_content', $post_id );
		$word_count      = AITRFOEL_Translation_Engine::count_words( wp_strip_all_tags( $content ) );
		$demo_word_limit = 500;

		if ( $word_count > $demo_word_limit ) {
			wp_send_json_success( [
				'skipped' => true,
				'reason'  => 'too_large',
				'message' => __( 'Homepage is large — skipped to keep setup fast.', 'ai-translator-for-elementor-and-polylang' ),
			] );
		}

		// Map language code to full name expected by the engine.
		$mapped_lang   = $target_lang;
		$mapped_source = $source_lang;
		if ( class_exists( 'AITRFOEL_Language_Mapper' ) ) {
			$name = AITRFOEL_Language_Mapper::get_language_name( $target_lang, false );
			if ( $name ) {
				$mapped_lang = $name;
			}
			if ( $source_lang !== 'auto' ) {
				$src_name = AITRFOEL_Language_Mapper::get_language_name( $source_lang, false );
				if ( $src_name ) {
					$mapped_source = $src_name;
				}
			}
		}

		$engine = AITRFOEL_Translation_Engine::get_instance();
		$result = $engine->translate_page( $post_id, $mapped_lang, $mapped_source );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [
			'skipped'        => false,
			'translated_id'  => $result,
			'translated_url' => get_permalink( $result ),
			'message'        => __( 'Homepage translated!', 'ai-translator-for-elementor-and-polylang' ),
		] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Import Polylang translation relationships into the built-in system.
	 *
	 * Polylang stores translation groups in the 'post_translations' taxonomy.
	 * Each term's description is a serialized array of lang_code => post_id.
	 * This method reads those groups and creates equivalent rows in the
	 * built-in aitrfoel_translation_groups table.
	 *
	 * @param AITRFOEL_Builtin_Integration $builtin
	 * @param string                       $default_lang_code
	 */
	private function import_polylang_translations( $builtin, $default_lang_code ) {
		global $wpdb;

		// Query the DB directly because Polylang may be deactivated (taxonomy unregistered)
		$pll_groups = $wpdb->get_results(
			"SELECT tt.term_taxonomy_id, t.slug, tt.description
             FROM {$wpdb->term_taxonomy} tt
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = 'post_translations'"
		);

		if ( empty( $pll_groups ) || ! is_array( $pll_groups ) ) {
			return; // No Polylang data to import
		}

		$table = $wpdb->prefix . 'aitrfoel_translation_groups';

		// Get built-in configured languages for validation
		$builtin_langs = [];
		foreach ( $builtin->get_available_languages() as $lang ) {
			$builtin_langs[] = is_object( $lang ) ? $lang->code : $lang['code'];
		}

		$imported = 0;

		foreach ( $pll_groups as $group ) {
			$translations = maybe_unserialize( $group->description );
			if ( ! is_array( $translations ) || count( $translations ) < 2 ) {
				continue; // Need at least 2 posts to form a group
			}

			// Filter to only include languages that exist in our built-in system
			$valid_translations = [];
			foreach ( $translations as $lang_code => $post_id ) {
				$post_id = intval( $post_id );
				if ( $post_id > 0 && in_array( $lang_code, $builtin_langs, true ) ) {
					// Verify the post actually exists
					if ( get_post_status( $post_id ) !== false ) {
						$valid_translations[ $lang_code ] = $post_id;
					}
				}
			}

			if ( count( $valid_translations ) < 2 ) {
				continue;
			}

			// Check if any post in this group already has a built-in group
			$already_imported = false;
			foreach ( $valid_translations as $lang_code => $post_id ) {
				$existing = $wpdb->get_var( $wpdb->prepare(
					"SELECT group_id FROM {$table} WHERE post_id = %d LIMIT 1",
					$post_id
				) );
				if ( $existing ) {
					$already_imported = true;
					break;
				}
			}
			if ( $already_imported ) {
				continue;
			}

			// Get next group_id
			$max_group    = $wpdb->get_var( "SELECT MAX(group_id) FROM {$table}" );
			$new_group_id = ( $max_group ? intval( $max_group ) : 0 ) + 1;

			// Insert all posts in this translation group
			foreach ( $valid_translations as $lang_code => $post_id ) {
				$wpdb->replace( $table, [
					'group_id'      => $new_group_id,
					'post_id'       => $post_id,
					'language_code' => $lang_code,
				], [ '%d', '%d', '%s' ] );

				// Also set the built-in language taxonomy on the post
				$builtin->set_post_language( $post_id, $lang_code );
			}

			$imported++;
		}

		if ( $imported > 0 && class_exists( 'AITRFOEL_Logger' ) ) {
			AITRFOEL_Logger::log( "Imported {$imported} Polylang translation groups into built-in system.", 'info' );
		}
	}
}
