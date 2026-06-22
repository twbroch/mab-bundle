// === MAB re-extraction importer (URL-fetch mode) ===
// Fetches a public manifest (article JSON) + figure PNGs server-side and applies
// them to live mab_article posts. No file_upload, no base64, no inbound WAF hit
// (the server makes OUTBOUND fetches, which BigScoots allows). Code Snippets, NOT WPCode.
// Safety: full backup of all 1,052 originals in media IDs 9906401-9906406.

add_action( 'wp_ajax_mab_import_nonce', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false ), 403 ); }
	wp_send_json( array( 'ok' => true, 'nonce' => wp_create_nonce( 'mab_import' ) ) );
} );

add_action( 'wp_ajax_mab_import', 'mab_import_handler' );

function mab_import_handler() {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false, 'error' => 'forbidden' ), 403 ); }
	check_ajax_referer( 'mab_import', 'nonce' );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$manifest_url = isset( $_POST['manifest_url'] ) ? esc_url_raw( wp_unslash( $_POST['manifest_url'] ) ) : '';
	if ( ! $manifest_url ) { wp_send_json( array( 'ok' => false, 'error' => 'no manifest_url' ), 400 ); }

	$only = array();
	if ( ! empty( $_POST['ids'] ) ) {
		foreach ( explode( ',', wp_unslash( $_POST['ids'] ) ) as $x ) {
			$x = intval( trim( $x ) );
			if ( $x ) { $only[ $x ] = true; }
		}
	}
	$commit = empty( $_POST['dryrun'] );

	$resp = wp_remote_get( $manifest_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $resp ) ) { wp_send_json( array( 'ok' => false, 'error' => 'manifest fetch: ' . $resp->get_error_message() ), 502 ); }
	$manifest = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $manifest ) || empty( $manifest['articles'] ) ) { wp_send_json( array( 'ok' => false, 'error' => 'bad manifest' ), 502 ); }

	$base = isset( $manifest['base_url'] ) ? $manifest['base_url'] : '';
	$results = array();

	foreach ( $manifest['articles'] as $art ) {
		$id = isset( $art['id'] ) ? intval( $art['id'] ) : 0;
		if ( ! $id ) { continue; }
		if ( $only && empty( $only[ $id ] ) ) { continue; }

		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'mab_article' ) {
			$results[] = array( 'id' => $id, 'ok' => false, 'error' => 'post not found / wrong type' );
			continue;
		}

		$content = (string) ( isset( $art['body_html'] ) ? $art['body_html'] : '' );
		$figures = ( isset( $art['figures'] ) && is_array( $art['figures'] ) ) ? $art['figures'] : array();
		usort( $figures, function ( $a, $b ) {
			return ( isset( $a['n'] ) ? intval( $a['n'] ) : 999 ) - ( isset( $b['n'] ) ? intval( $b['n'] ) : 999 );
		} );

		$fig_log = array();
		$unplaced = array();
		foreach ( $figures as $fig ) {
			$file = isset( $fig['file'] ) ? $fig['file'] : '';
			$num  = isset( $fig['n'] ) ? intval( $fig['n'] ) : 0;
			$cap  = isset( $fig['caption'] ) ? trim( (string) $fig['caption'] ) : '';
			if ( ! $file ) { continue; }
			$fig_url = $base . $file;

			$src_url = mab_existing_fig_url( $id, $file );
			$att_id  = 0;
			if ( ! $src_url ) {
				$att_id = media_sideload_image( $fig_url, $id, ( $cap !== '' ? $cap : null ), 'id' );
				if ( is_wp_error( $att_id ) ) {
					$fig_log[] = array( 'file' => $file, 'ok' => false, 'error' => $att_id->get_error_message() );
					continue;
				}
				update_post_meta( $att_id, '_mab_fig_key', $file );
				$src_url = wp_get_attachment_url( $att_id );
			}

			$figHtml = mab_fig_html( $src_url, $cap );
			$ph  = '[[FIGURE:' . $num . ']]';
			$pos = ( $num && strpos( $content, $ph ) !== false ) ? strpos( $content, $ph ) : false;
			if ( $pos !== false ) {
				$content = substr_replace( $content, $figHtml, $pos, strlen( $ph ) );
			} else {
				$unplaced[] = $figHtml;
			}
			$fig_log[] = array( 'file' => $file, 'ok' => true, 'att_id' => intval( $att_id ), 'url' => $src_url, 'placed' => ( $pos !== false ) );
		}

		// Strip any leftover/duplicate placeholders, append unplaced figures.
		$content = preg_replace( '/\[\[FIGURE:\d+\]\]/', '', $content );
		if ( $unplaced ) { $content .= "\n" . implode( "\n", $unplaced ); }
		$content = trim( $content );

		$new_title = isset( $art['title'] ) ? trim( (string) $art['title'] ) : $post->post_title;

		$entry = array(
			'id' => $id, 'ok' => true,
			'old_title' => $post->post_title, 'new_title' => $new_title,
			'figs' => $fig_log, 'content_len' => strlen( $content ), 'committed' => false,
		);
		if ( $commit ) {
			$upd = wp_update_post( array( 'ID' => $id, 'post_title' => $new_title, 'post_content' => $content ), true );
			if ( is_wp_error( $upd ) ) { $entry['ok'] = false; $entry['error'] = $upd->get_error_message(); }
			else { $entry['committed'] = true; }
		}
		$results[] = $entry;
	}

	wp_send_json( array( 'ok' => true, 'commit' => $commit, 'count' => count( $results ), 'results' => $results ) );
}

function mab_existing_fig_url( $post_id, $file ) {
	$q = get_posts( array(
		'post_type' => 'attachment', 'post_parent' => $post_id, 'post_status' => 'inherit',
		'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mab_fig_key', 'meta_value' => $file,
	) );
	if ( $q ) { $u = wp_get_attachment_url( $q[0] ); if ( $u ) { return $u; } }
	return '';
}

function mab_fig_html( $url, $caption ) {
	$alt  = $caption !== '' ? esc_attr( $caption ) : '';
	$html = '<figure class="mab-figure"><img src="' . esc_url( $url ) . '" alt="' . $alt . '" loading="lazy" />';
	if ( $caption !== '' ) { $html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>'; }
	$html .= '</figure>';
	return $html;
}
