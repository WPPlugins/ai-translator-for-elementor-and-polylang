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
			__( 'Review Translations', 'ai-translator-for-elementor-and-polylang' ),
			__( 'Review Translations', 'ai-translator-for-elementor-and-polylang' ),
			'edit_posts',
			'ai-translator-for-elementor-and-polylang-review',
			[ $this, 'render_page' ]
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
			$this->render_selector_page();
			return;
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

		// Pair segments by structural path to guarantee 1-to-1 alignment between source & translation.
		$paired_segments = [];
		$all_paths = array_unique( array_merge( array_keys( $source_segments ), array_keys( $trans_segments ) ) );
		foreach ( $all_paths as $path ) {
			$src = $source_segments[ $path ] ?? null;
			$trs = $trans_segments[ $path ] ?? null;
			$src_text = isset( $src['text'] ) ? $src['text'] : '';
			$trs_text = isset( $trs['text'] ) ? $trs['text'] : '';

			if ( trim( wp_strip_all_tags( $src_text ) ) === '' && trim( wp_strip_all_tags( $trs_text ) ) === '' ) {
				continue;
			}

			$paired_segments[] = [
				'path'        => $path,
				'source_text' => $src_text,
				'trans_text'  => $trs_text,
				'type'        => $src['type'] ?? ( $trs['type'] ?? $content_type ),
				'label'       => $src['label'] ?? ( $trs['label'] ?? '' ),
			];
		}

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
					<?php $aitrfoel_is_draft = in_array( $trans_post->post_status, [ 'draft', 'pending', 'auto-draft' ], true ); ?>
					<button type="button" id="aitrfoel-review-action" class="button button-primary"
						data-mode="<?php echo $aitrfoel_is_draft ? 'publish' : 'update'; ?>"
						data-page-id="<?php echo absint( $post_id ); ?>"
						data-source-id="<?php echo absint( $source_id ); ?>"
						data-lang="<?php echo esc_attr( $lang ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( 'aitrfoel_ajax_nonce' ) ); ?>">
						<?php echo $aitrfoel_is_draft
							? esc_html__( 'Publish', 'ai-translator-for-elementor-and-polylang' )
							: esc_html__( 'Update', 'ai-translator-for-elementor-and-polylang' ); ?>
					</button>
				</div>
			</div>
			<script>
			(function($){
				var $b = $('#aitrfoel-review-action');
				var T = {
					publish:   '<?php echo esc_js( __( 'Publish', 'ai-translator-for-elementor-and-polylang' ) ); ?>',
					update:    '<?php echo esc_js( __( 'Update', 'ai-translator-for-elementor-and-polylang' ) ); ?>',
					working:   '<?php echo esc_js( __( 'Working…', 'ai-translator-for-elementor-and-polylang' ) ); ?>',
					confirm:   '<?php echo esc_js( __( 'Re-translate this page from the source? This refreshes the translation and may overwrite manual edits made here.', 'ai-translator-for-elementor-and-polylang' ) ); ?>',
					failed:    '<?php echo esc_js( __( 'Action failed.', 'ai-translator-for-elementor-and-polylang' ) ); ?>'
				};
				$b.on('click', function(){
					var mode = $b.data('mode');
					if (mode === 'publish') {
						$b.prop('disabled', true).text(T.working);
						$.post(ajaxurl, { action: 'aitrfoel_publish_page', nonce: $b.data('nonce'), page_id: $b.data('page-id') }, function(r){
							$b.prop('disabled', false);
							if (r && r.success) { $b.data('mode', 'update').text(T.update); }
							else { $b.text(T.publish); alert((r && r.data && r.data.message) || T.failed); }
						});
					} else {
						if (!window.confirm(T.confirm)) { return; }
						$b.prop('disabled', true).text(T.working);
						$.post(ajaxurl, { action: 'aitrfoel_retranslate_post', nonce: $b.data('nonce'), source_id: $b.data('source-id'), lang: $b.data('lang') }, function(r){
							if (r && r.success) { window.location.reload(); }
							else { $b.prop('disabled', false).text(T.update); alert((r && r.data && r.data.message) || T.failed); }
						});
					}
				});
			})(jQuery);
			</script>

			<div id="aitrfoel-review-notice" class="aitrfoel-review-notice" style="display:none;" aria-live="polite"></div>

			<?php if ( empty( $paired_segments ) ) : ?>
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
					foreach ( $paired_segments as $i => $segment ) :
						$trs_text    = $segment['trans_text'];
						$trs_path    = $segment['path'];
						$src_display = wp_strip_all_tags( $segment['source_text'] );
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
					<?php endforeach; ?>
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
	 * Extract translatable segments from a post keyed by structural path.
	 *
	 * @param int $post_id
	 * @return array<string, array{key:string, path:string, text:string, type:string, label:string}>
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
						$translation_map['woocommerce/title'] = [
							'original' => (string) $post->post_title,
							'path'     => 'woocommerce/title',
							'type'     => 'text',
						];
					}

					if ( ! empty( trim( (string) $post->post_excerpt ) ) ) {
						$translation_map['woocommerce/excerpt'] = [
							'original' => (string) $post->post_excerpt,
							'path'     => 'woocommerce/excerpt',
							'type'     => 'text',
						];
					}

					if ( 'sc_product' !== $post->post_type && ! empty( trim( (string) $post->post_content ) ) ) {
						$translation_map['woocommerce/content'] = [
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

		// Custom-field text (JetEngine / ACF / Meta Box / plain meta) — editable even
		// when the body is empty (the common case for meta-only JetEngine CPTs that
		// would otherwise show "no editable segments").
		if ( class_exists( 'AITRFOEL_Translation_Engine' ) ) {
			$engine = AITRFOEL_Translation_Engine::get_instance();
			if ( method_exists( $engine, 'get_translatable_meta_map' ) ) {
				foreach ( $engine->get_translatable_meta_map( $post_id ) as $meta_key => $meta_val ) {
					$translation_map[ 'meta/' . $meta_key ] = [
						'original' => (string) $meta_val,
						'path'     => 'meta/' . $meta_key,
						'type'     => 'meta',
						'label'    => $meta_key,
					];
				}
			}
		}

		$segments = [];
		foreach ( $translation_map as $key => $item ) {
			$text = is_array( $item['original'] ) ? implode( "\n", $item['original'] ) : (string) $item['original'];
			$path = $item['path'] ?? (string) $key;
			$segments[ $path ] = [
				'key'   => $key,
				'path'  => $path,
				'text'  => $text,
				'type'  => $type,
				'label' => $item['label'] ?? '',
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

		// Custom-field segments (JetEngine / ACF / other meta) — identified by a
		// "meta/{key}" path — save straight to post meta regardless of content type.
		if ( strpos( $path, 'meta/' ) === 0 ) {
			$meta_key = substr( $path, strlen( 'meta/' ) );
			// Only the custom fields this screen lists for the post may be written;
			// never arbitrary/internal meta (_wp_attached_file, _wp_page_template, ...).
			$allowed_keys = [];
			if ( class_exists( 'AITRFOEL_Translation_Engine' ) ) {
				$engine = AITRFOEL_Translation_Engine::get_instance();
				if ( method_exists( $engine, 'get_translatable_meta_map' ) ) {
					$allowed_keys = array_map( 'strval', array_keys( (array) $engine->get_translatable_meta_map( $post_id ) ) );
				}
			}
			if ( $meta_key === '' || ! in_array( $meta_key, $allowed_keys, true ) ) {
				wp_send_json_error( [ 'message' => __( 'Invalid field.', 'ai-translator-for-elementor-and-polylang' ) ] );
			}
			update_post_meta( $post_id, $meta_key, $new_text );
			wp_send_json_success( [ 'message' => __( 'Segment saved.', 'ai-translator-for-elementor-and-polylang' ) ] );
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

		// Elementor v4 atomic widgets store text in a typed prop object
		// ({"$$type":"html-v3","value":[...]}); replace the text inside it instead of
		// overwriting the object with a plain string, which breaks the widget.
		if ( is_array( $current[ $last_key ] ) && isset( $current[ $last_key ]['$$type'] ) && class_exists( 'AITRFOEL_Widget_Parser' ) ) {
			$current[ $last_key ] = AITRFOEL_Widget_Parser::get_instance()->apply_atomic_translation( $current[ $last_key ], $new_text );
		} else {
			$current[ $last_key ] = $new_text;
		}

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

	/**
	 * Render translation selector screen when no post_id is provided.
	 */
	public function render_selector_page() {
		$admin_menu   = class_exists( 'AITRFOEL_Admin_Menu' ) ? AITRFOEL_Admin_Menu::get_instance() : null;
		$pages_data   = [];
		if ( $admin_menu ) {
			if ( method_exists( $admin_menu, 'get_pages_with_translations' ) ) {
				$pages_data = $admin_menu->get_pages_with_translations();
			} elseif ( method_exists( $admin_menu, 'get_all_pages_with_translations' ) ) {
				$pages_data = $admin_menu->get_all_pages_with_translations();
			}
		}

		// Filter for items that have at least one translation
		$translated_items = [];
		foreach ( $pages_data as $group ) {
			if ( ! empty( $group['translations'] ) ) {
				$translated_items[] = $group;
			}
		}
		?>
		<div class="wrap aitrfoel-admin-wrapper aitrfoel-review-selector-wrap">
			<?php
			if ( $admin_menu ) {
				$admin_menu->render_plugin_header( __( 'Review Translations', 'ai-translator-for-elementor-and-polylang' ) );
			}
			?>

			<div class="aitrfoel-settings-section" style="margin-top:20px;">
				<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
					<div>
						<h3 style="margin:0 0 6px;"><?php esc_html_e( 'Side-by-Side Review Workspace', 'ai-translator-for-elementor-and-polylang' ); ?></h3>
						<p class="description" style="margin:0;">
							<?php esc_html_e( 'Select any translated page, template, or post to view its original source text and translated segments side-by-side with full inline editing capability.', 'ai-translator-for-elementor-and-polylang' ); ?>
						</p>
					</div>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-translator-for-elementor-and-polylang-translate-all' ) ); ?>" class="button button-primary" style="display:inline-flex;align-items:center;gap:6px;">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
						<span><?php esc_html_e( 'Translate More Content', 'ai-translator-for-elementor-and-polylang' ); ?></span>
					</a>
				</div>

				<?php if ( empty( $translated_items ) ) : ?>
					<div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:12px;padding:48px 24px;text-align:center;">
						<div style="margin-bottom:12px;color:#94a3b8;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
						<h4 style="margin:0 0 8px;font-size:16px;color:#0f172a;"><?php esc_html_e( 'No Translations Ready for Review Yet', 'ai-translator-for-elementor-and-polylang' ); ?></h4>
						<p style="color:#64748b;max-width:500px;margin:0 auto 20px;font-size:13px;">
							<?php esc_html_e( 'Once you translate pages or posts using Meep AI Translator, they will appear here for side-by-side diff review and granular segment editing.', 'ai-translator-for-elementor-and-polylang' ); ?>
						</p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-translator-for-elementor-and-polylang-translate-all' ) ); ?>" class="button button-primary button-hero">
							<?php esc_html_e( 'Start Batch Translation', 'ai-translator-for-elementor-and-polylang' ); ?>
						</a>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 40%;"><?php esc_html_e( 'Source Content', 'ai-translator-for-elementor-and-polylang' ); ?></th>
								<th style="width: 20%;"><?php esc_html_e( 'Content Type', 'ai-translator-for-elementor-and-polylang' ); ?></th>
								<th style="width: 40%;"><?php esc_html_e( 'Available Translations & Review', 'ai-translator-for-elementor-and-polylang' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $translated_items as $item ) : 
								$src = $item['source'];
								$pt_label = ucfirst( $src->post_type );
								if ( $src->post_type === 'elementor-hf' ) { $pt_label = 'Header/Footer'; }
								elseif ( $src->post_type === 'elementor_library' ) { $pt_label = 'Elementor Template'; }
								elseif ( strpos( $src->post_type, 'form' ) !== false ) { $pt_label = 'Form'; }
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $src->post_title ); ?></strong>
									<div style="font-size:12px;color:#64748b;margin-top:2px;">
										ID #<?php echo absint( $src->ID ); ?> &middot; <?php echo esc_html( get_the_date( '', $src->ID ) ); ?>
									</div>
								</td>
								<td>
									<span class="badge" style="background:#f1f5f9;color:#334155;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600;">
										<?php echo esc_html( $pt_label ); ?>
									</span>
								</td>
								<td>
									<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
										<?php foreach ( $item['translations'] as $tlang => $tpost ) : 
											$review_url = admin_url( sprintf(
												'admin.php?page=ai-translator-for-elementor-and-polylang-review&source_id=%d&post_id=%d&lang=%s',
												$src->ID,
												$tpost->ID,
												urlencode( $tlang )
											) );
										?>
											<a href="<?php echo esc_url( $review_url ); ?>" class="button button-small" style="display:inline-flex;align-items:center;gap:5px;">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
												<strong><?php echo esc_html( strtoupper( $tlang ) ); ?></strong>
												<span style="color:#64748b;font-size:10px;">(<?php echo esc_html( $tpost->post_status ); ?>)</span>
											</a>
										<?php endforeach; ?>
									</div>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
