<?php
/**
 * Review Editor — side-by-side diff workspace for reviewing AI translations
 *
 * Registers a hidden admin subpage (?page=…-review) that shows source and
 * translated segments in a two-column table with inline editing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AITRFOEL_Review_Editor {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'register_page' ], 25 );
		// Hide from submenu after WP has validated the page slug (admin_head fires after admin_menu).
		add_action( 'admin_head', [ $this, 'hide_from_submenu' ] );
		// Enqueue assets in <head>, not mid-render.
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_aitrfoel_save_segment_edit', [ $this, 'handle_save_segment' ] );
	}

	// -------------------------------------------------------------------------
	// Menu registration
	// -------------------------------------------------------------------------

	public function register_page() {
		add_submenu_page(
			'ai-translator-for-elementor-and-polylang',
			__( 'Review Translation', 'ai-translator-for-elementor-and-polylang' ),
			__( 'Review Translation', 'ai-translator-for-elementor-and-polylang' ),
			'edit_posts',
			'ai-translator-for-elementor-and-polylang-review',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Remove the Review page from the visible submenu.
	 * Called on admin_head (after admin_menu) so WordPress has already validated
	 * the page slug — removing it too early caused a dashboard redirect.
	 */
	public function hide_from_submenu() {
		remove_submenu_page(
			'ai-translator-for-elementor-and-polylang',
			'ai-translator-for-elementor-and-polylang-review'
		);
	}

	// -------------------------------------------------------------------------
	// Asset enqueuing
	// -------------------------------------------------------------------------

	public function enqueue_assets( $hook ) {
		// Only load on our review page.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'ai-translator-for-elementor-and-polylang-review' !== $page ) {
			return;
		}

		// dashboard.css provides the meep-hero / meep-reveal styles used by render_plugin_header().
		wp_enqueue_style(
			'aitrfoel-dashboard',
			AITRFOEL_PLUGIN_URL . 'assets/css/dashboard.css',
			[ 'aitrfoel-meep-admin' ],
			AITRFOEL_VERSION
		);

		wp_enqueue_style(
			'aitrfoel-review-editor',
			AITRFOEL_PLUGIN_URL . 'assets/css/review-editor.css',
			[ 'aitrfoel-dashboard' ],
			AITRFOEL_VERSION
		);

		wp_enqueue_script(
			'aitrfoel-review-editor',
			AITRFOEL_PLUGIN_URL . 'assets/js/review-editor.js',
			[ 'jquery' ],
			AITRFOEL_VERSION,
			true
		);

		$post_id_for_type = absint( $_GET['post_id'] ?? 0 );
		$content_type     = ( $post_id_for_type && class_exists( 'AITRFOEL_Content_Detector' ) )
			? AITRFOEL_Content_Detector::get_instance()->detect( $post_id_for_type )
			: 'classic';

		wp_localize_script( 'aitrfoel-review-editor', 'aitrfoelReview', [
			'ajax_url'     => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'aitrfoel_review_edit' ),
			'post_id'      => $post_id_for_type,
			'source_id'    => absint( $_GET['source_id'] ?? 0 ),
			'content_type' => $content_type,
			'strings'      => [
				'save'        => __( 'Save', 'ai-translator-for-elementor-and-polylang' ),
				'cancel'      => __( 'Cancel', 'ai-translator-for-elementor-and-polylang' ),
				'saving'      => __( 'Saving…', 'ai-translator-for-elementor-and-polylang' ),
				'saved'       => __( 'Saved', 'ai-translator-for-elementor-and-polylang' ),
				'save_failed' => __( 'Save failed. Please try again.', 'ai-translator-for-elementor-and-polylang' ),
			],
		] );
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$source_id = absint( $_GET['source_id'] ?? 0 );
		$post_id   = absint( $_GET['post_id'] ?? 0 );
		$lang      = sanitize_text_field( $_GET['lang'] ?? '' );

		if ( ! $source_id || ! $post_id ) {
			wp_die( esc_html__( 'Invalid parameters.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$source_post = get_post( $source_id );
		$trans_post  = get_post( $post_id );

		if ( ! $source_post || ! $trans_post ) {
			wp_die( esc_html__( 'Post not found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$content_type    = class_exists( 'AITRFOEL_Content_Detector' )
			? AITRFOEL_Content_Detector::get_instance()->detect( $post_id )
			: 'classic';
		$source_segments = $this->get_segments( $source_id );
		$trans_segments  = $this->get_segments( $post_id );

		$dashboard_url = admin_url( 'admin.php?page=ai-translator-for-elementor-and-polylang' );
		$view_url      = get_permalink( $post_id );

		?>
		<div class="wrap aitrfoel-admin-wrapper aitrfoel-review-wrap">
			<?php
			if ( class_exists( 'AITRFOEL_Admin_Menu' ) ) {
				AITRFOEL_Admin_Menu::get_instance()->render_plugin_header( 'Review' );
			}
			?>

			<div class="aitrfoel-review-header">
				<div class="aitrfoel-review-title-row">
					<h2 class="aitrfoel-review-page-title">
						<?php echo esc_html( $source_post->post_title ); ?>
					</h2>
					<?php if ( $lang ) : ?>
						<span class="aitrfoel-review-lang-badge">
							<?php echo esc_html( strtoupper( $lang ) ); ?>
						</span>
					<?php endif; ?>
				</div>
				<div class="aitrfoel-review-header-actions">
					<a href="<?php echo esc_url( $dashboard_url ); ?>" class="button">
						&larr; <?php esc_html_e( 'Dashboard', 'ai-translator-for-elementor-and-polylang' ); ?>
					</a>
					<a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>" class="button">
						<?php esc_html_e( 'Edit in WP', 'ai-translator-for-elementor-and-polylang' ); ?>
					</a>
					<a href="<?php echo esc_url( $view_url ); ?>" class="button" target="_blank" rel="noopener">
						<?php esc_html_e( 'View Translation', 'ai-translator-for-elementor-and-polylang' ); ?>
					</a>
				</div>
			</div>

			<div id="aitrfoel-review-notice" class="aitrfoel-review-notice" style="display:none;" aria-live="polite"></div>

			<?php if ( empty( $trans_segments ) ) : ?>
				<div class="aitrfoel-review-empty">
					<p><?php esc_html_e( 'No editable segments were found for this translation.', 'ai-translator-for-elementor-and-polylang' ); ?></p>
				</div>
			<?php else : ?>
			<table class="aitrfoel-review-table">
				<thead>
					<tr>
						<th class="aitrfoel-review-col-num">#</th>
						<th class="aitrfoel-review-col-source">
							<?php esc_html_e( 'Source', 'ai-translator-for-elementor-and-polylang' ); ?>
						</th>
						<th class="aitrfoel-review-col-trans">
							<?php
							printf(
								/* translators: %s: uppercase language code */
								esc_html__( 'Translation (%s)', 'ai-translator-for-elementor-and-polylang' ),
								esc_html( strtoupper( $lang ) )
							);
							?>
						</th>
					</tr>
				</thead>
				<tbody>
					<?php
					$count = max( count( $source_segments ), count( $trans_segments ) );
					for ( $i = 0; $i < $count; $i++ ) :
						$src = $source_segments[ $i ] ?? null;
						$trs = $trans_segments[ $i ] ?? null;
						if ( ! $src && ! $trs ) {
							continue;
						}
						$trs_text    = isset( $trs['text'] ) ? $trs['text'] : '';
						$trs_path    = isset( $trs['path'] ) ? $trs['path'] : '';
						$src_display = isset( $src['text'] ) ? wp_strip_all_tags( $src['text'] ) : '';
						$trs_display = wp_strip_all_tags( $trs_text );
						?>
						<tr class="aitrfoel-review-row"
							data-index="<?php echo absint( $i ); ?>"
							data-path="<?php echo esc_attr( $trs_path ); ?>">
							<td class="aitrfoel-review-col-num"><?php echo absint( $i + 1 ); ?></td>
							<td class="aitrfoel-review-source">
								<?php echo nl2br( esc_html( $src_display ) ); ?>
							</td>
							<td class="aitrfoel-review-trans">
								<div class="aitrfoel-review-display"
									 title="<?php esc_attr_e( 'Click to edit', 'ai-translator-for-elementor-and-polylang' ); ?>">
									<?php echo nl2br( esc_html( $trs_display ) ); ?>
								</div>
								<textarea
									class="aitrfoel-review-ta"
									style="display:none;"
									data-original="<?php echo esc_attr( $trs_text ); ?>"
									rows="4"
								><?php echo esc_textarea( $trs_text ); ?></textarea>
								<div class="aitrfoel-review-edit-actions" style="display:none;">
									<button type="button" class="button button-primary aitrfoel-save-btn">
										<?php esc_html_e( 'Save', 'ai-translator-for-elementor-and-polylang' ); ?>
									</button>
									<button type="button" class="button aitrfoel-cancel-btn">
										<?php esc_html_e( 'Cancel', 'ai-translator-for-elementor-and-polylang' ); ?>
									</button>
								</div>
							</td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Segment extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract translatable segments from a post.
	 *
	 * @param int $post_id
	 * @return array  Ordered list of ['key', 'path', 'text', 'type']
	 */
	private function get_segments( $post_id ) {
		if ( ! class_exists( 'AITRFOEL_Content_Detector' ) ) {
			return [];
		}

		$type = AITRFOEL_Content_Detector::get_instance()->detect( $post_id );

		$translation_map = [];

		switch ( $type ) {
			case 'elementor':
				if ( class_exists( 'AITRFOEL_Widget_Parser' ) ) {
					$raw = get_post_meta( $post_id, '_elementor_data', true );
					if ( ! empty( $raw ) ) {
						$decoded = json_decode( $raw, true );
						if ( is_array( $decoded ) ) {
							$translation_map = AITRFOEL_Widget_Parser::get_instance()->parse_for_translation( $decoded );
						}
					}
				}
				break;

			case 'gutenberg':
				if ( class_exists( 'AITRFOEL_Gutenberg_Parser' ) ) {
					$translation_map = AITRFOEL_Gutenberg_Parser::get_instance()->parse_for_translation( $post_id );
				}
				break;

			case 'woocommerce':
				$post = get_post( $post_id );
				if ( $post ) {
					if ( ! empty( $post->post_title ) ) {
						$translation_map['woo_title'] = [
							'original' => (string) $post->post_title,
							'path'     => 'woocommerce/title',
							'type'     => 'text',
						];
					}

					if ( ! empty( trim( (string) $post->post_excerpt ) ) ) {
						$translation_map['woo_excerpt'] = [
							'original' => (string) $post->post_excerpt,
							'path'     => 'woocommerce/excerpt',
							'type'     => 'text',
						];
					}

					if ( ! empty( trim( (string) $post->post_content ) ) ) {
						$translation_map['woo_content'] = [
							'original' => (string) $post->post_content,
							'path'     => 'woocommerce/content',
							'type'     => 'html',
						];
					}
				}
				break;

			case 'classic':
			default:
				if ( class_exists( 'AITRFOEL_Classic_Parser' ) ) {
					$translation_map = AITRFOEL_Classic_Parser::get_instance()->parse_for_translation( $post_id );
				}
				break;
		}

		$segments = [];
		foreach ( $translation_map as $key => $item ) {
			$text = is_array( $item['original'] ) ? implode( "\n", $item['original'] ) : (string) $item['original'];
			if ( trim( wp_strip_all_tags( $text ) ) === '' ) {
				continue;
			}
			$segments[] = [
				'key'  => $key,
				'path' => $item['path'] ?? $key,
				'text' => $text,
				'type' => $type,
			];
		}

		return $segments;
	}

	// -------------------------------------------------------------------------
	// AJAX: save a single segment
	// -------------------------------------------------------------------------

	public function handle_save_segment() {
		check_ajax_referer( 'aitrfoel_review_edit', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'ai-translator-for-elementor-and-polylang' ) ] );
		}

		$post_id      = absint( $_POST['post_id'] ?? 0 );
		$path         = sanitize_text_field( $_POST['path'] ?? '' );
		$new_text     = wp_kses_post( wp_unslash( $_POST['new_text'] ?? '' ) );
		$content_type = sanitize_text_field( $_POST['content_type'] ?? '' );

		if ( ! $post_id || $path === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing parameters.', 'ai-translator-for-elementor-and-polylang' ) ] );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions for this post.', 'ai-translator-for-elementor-and-polylang' ) ] );
		}

		switch ( $content_type ) {
			case 'elementor':
				$result = $this->save_elementor_segment( $post_id, $path, $new_text );
				break;
			case 'gutenberg':
				$result = $this->save_gutenberg_segment( $post_id, $path, $new_text );
				break;
			case 'woocommerce':
				$result = $this->save_woocommerce_segment( $post_id, $path, $new_text );
				break;
			default:
				$result = $this->save_classic_segment( $post_id, $path, $new_text );
				break;
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( [ 'message' => __( 'Segment saved.', 'ai-translator-for-elementor-and-polylang' ) ] );
	}

	// -------------------------------------------------------------------------
	// Save helpers
	// -------------------------------------------------------------------------

	private function save_classic_segment( $post_id, $path, $new_text ) {
		// path format: classic/N
		$parts = explode( '/', $path );
		$index = isset( $parts[1] ) ? absint( $parts[1] ) : -1;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! class_exists( 'AITRFOEL_Classic_Parser' ) ) {
			return new WP_Error( 'no_parser', __( 'Classic parser unavailable.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$chunks = AITRFOEL_Classic_Parser::get_instance()->extract_text_from_html( $post->post_content );

		if ( $index < 0 || ! isset( $chunks[ $index ] ) ) {
			return new WP_Error( 'invalid_index', __( 'Segment not found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$old_chunk   = $chunks[ $index ];
		$new_content = str_replace( $old_chunk, $new_text, $post->post_content );

		$result = wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ], true );
		return is_wp_error( $result ) ? $result : true;
	}

	private function save_gutenberg_segment( $post_id, $path, $new_text ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( ! class_exists( 'AITRFOEL_Gutenberg_Parser' ) ) {
			return new WP_Error( 'no_parser', __( 'Gutenberg parser unavailable.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		// Look up the old text via the parser, then do a targeted replacement
		$translation_map = AITRFOEL_Gutenberg_Parser::get_instance()->parse_for_translation( $post_id );
		$old_text        = null;

		foreach ( $translation_map as $item ) {
			if ( ( $item['path'] ?? '' ) === $path ) {
				$old_text = $item['original'];
				break;
			}
		}

		if ( $old_text === null ) {
			return new WP_Error( 'not_found', __( 'Segment not found in Gutenberg content.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$new_content = str_replace( $old_text, $new_text, $post->post_content );
		$result      = wp_update_post( [ 'ID' => $post_id, 'post_content' => $new_content ], true );
		return is_wp_error( $result ) ? $result : true;
	}

	private function save_elementor_segment( $post_id, $path, $new_text ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return new WP_Error( 'no_data', __( 'No Elementor data found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bad_json', __( 'Elementor data could not be decoded.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		// Navigate the dot-path and write the value directly.
		// This bypasses apply_translations() whose validate_elementor_structure()
		// guard can silently return the original array on validation failure.
		$keys    = explode( '.', $path );
		$current = &$data;
		$last    = array_pop( $keys );

		foreach ( $keys as $key ) {
			$k = is_numeric( $key ) ? (int) $key : $key;
			if ( ! isset( $current[ $k ] ) || ! is_array( $current[ $k ] ) ) {
				return new WP_Error( 'bad_path', __( 'Segment path not found in Elementor data.', 'ai-translator-for-elementor-and-polylang' ) );
			}
			$current = &$current[ $k ];
		}

		$last_key = is_numeric( $last ) ? (int) $last : $last;
		if ( ! array_key_exists( $last_key, $current ) ) {
			return new WP_Error( 'bad_path', __( 'Segment path not found in Elementor data.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		$current[ $last_key ] = $new_text;

		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return new WP_Error( 'encode_fail', __( 'Failed to encode Elementor data.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );

		// Clear Elementor's file-based cache so the frontend reflects the change immediately.
		delete_post_meta( $post_id, '_elementor_css' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		clean_post_cache( $post_id );

		return true;
	}

	private function save_woocommerce_segment( $post_id, $path, $new_text ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		switch ( $path ) {
			case 'woocommerce/title':
				$result = wp_update_post( [
					'ID'         => $post_id,
					'post_title' => wp_strip_all_tags( $new_text ),
				], true );
				break;

			case 'woocommerce/excerpt':
				$result = wp_update_post( [
					'ID'           => $post_id,
					'post_excerpt' => $new_text,
				], true );
				break;

			case 'woocommerce/content':
				$result = wp_update_post( [
					'ID'           => $post_id,
					'post_content' => $new_text,
				], true );
				break;

			default:
				return new WP_Error( 'bad_path', __( 'Segment path not found for WooCommerce content.', 'ai-translator-for-elementor-and-polylang' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		clean_post_cache( $post_id );
		return true;
	}
}
