// === MAB re-extraction importer — ASYNC (queue + one-article-per-tick) ===
// Fixes the stall/wedge: mab_import QUEUES a job server-side and returns instantly;
// mab_run_one processes ONE article per call (fast, under PHP limits) — driven by
// WP-Cron (on site traffic) AND by the browser polling mab_run_one. mab_status reports
// progress. State persists in the `mab_job` option (survives tab reload). A transient
// lock prevents concurrent ticks. Idempotent (media reused via _mab_fig_key).
// Code Snippets (NOT WPCode). No base64. Backup of all 1,052 originals = media 9906401-06.

add_action( 'wp_ajax_mab_import_nonce', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false ), 403 ); }
	wp_send_json( array( 'ok' => true, 'nonce' => wp_create_nonce( 'mab_import' ) ) );
} );

// Read-only inventory (unchanged): every mab_article with id/title/slug/meta.
add_action( 'wp_ajax_mab_inventory', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false ), 403 ); }
	$ids = get_posts( array( 'post_type' => 'mab_article', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
	$out = array();
	foreach ( $ids as $id ) {
		$p = get_post( $id ); $meta = array();
		foreach ( get_post_meta( $id ) as $k => $v ) {
			$val = ( is_array( $v ) && count( $v ) === 1 ) ? $v[0] : $v;
			if ( is_string( $val ) && strlen( $val ) > 200 ) { $val = substr( $val, 0, 200 ); }
			$meta[ $k ] = $val;
		}
		$out[] = array( 'id' => $id, 'title' => $p->post_title, 'slug' => $p->post_name,
			'status' => $p->post_status, 'clen' => strlen( $p->post_content ),
			'imgs' => substr_count( $p->post_content, '<img' ), 'meta' => $meta );
	}
	wp_send_json( array( 'ok' => true, 'count' => count( $out ), 'posts' => $out ) );
} );

// ---- QUEUE a job: fetch manifest, store requested articles, kick cron, return now ----
add_action( 'wp_ajax_mab_import', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false, 'error' => 'forbidden' ), 403 ); }
	check_ajax_referer( 'mab_import', 'nonce' );
	$manifest_url = isset( $_POST['manifest_url'] ) ? esc_url_raw( wp_unslash( $_POST['manifest_url'] ) ) : '';
	if ( ! $manifest_url ) { wp_send_json( array( 'ok' => false, 'error' => 'no manifest_url' ), 400 ); }
	$only = array();
	if ( ! empty( $_POST['ids'] ) ) {
		foreach ( explode( ',', wp_unslash( $_POST['ids'] ) ) as $x ) { $x = intval( trim( $x ) ); if ( $x ) { $only[ $x ] = true; } }
	}
	$resp = wp_remote_get( $manifest_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $resp ) ) { wp_send_json( array( 'ok' => false, 'error' => 'manifest: ' . $resp->get_error_message() ), 502 ); }
	$manifest = json_decode( wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $manifest ) || empty( $manifest['articles'] ) ) { wp_send_json( array( 'ok' => false, 'error' => 'bad manifest' ), 502 ); }
	$base = isset( $manifest['base_url'] ) ? $manifest['base_url'] : '';
	$articles = array(); $pending = array();
	foreach ( $manifest['articles'] as $art ) {
		$id = isset( $art['id'] ) ? intval( $art['id'] ) : 0;
		if ( ! $id ) { continue; }
		if ( $only && empty( $only[ $id ] ) ) { continue; }
		$articles[ $id ] = $art; $pending[] = $id;
	}
	$job = array( 'base' => $base, 'pending' => $pending, 'articles' => $articles, 'done' => array(), 'started' => time() );
	update_option( 'mab_job', $job, false );
	delete_transient( 'mab_lock' );
	wp_clear_scheduled_hook( 'mab_run_one' );
	wp_schedule_single_event( time(), 'mab_run_one' );
	spawn_cron();
	wp_send_json( array( 'ok' => true, 'queued' => count( $pending ), 'ids' => $pending ) );
} );

// ---- process ONE pending article (shared by cron hook + ajax driver) ----
function mab_run_one_cb() {
	if ( get_transient( 'mab_lock' ) ) { return array( 'skipped' => 'locked' ); }
	set_transient( 'mab_lock', 1, 120 );
	$job = get_option( 'mab_job' );
	if ( ! is_array( $job ) || empty( $job['pending'] ) ) { delete_transient( 'mab_lock' ); return array( 'idle' => true ); }
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$id = array_shift( $job['pending'] );
	update_option( 'mab_job', $job, false ); // persist the shift before slow work
	$art = isset( $job['articles'][ $id ] ) ? $job['articles'][ $id ] : null;
	$res = $art ? mab_process_one( $job['base'], $art ) : array( 'id' => $id, 'ok' => false, 'error' => 'missing art' );
	$job = get_option( 'mab_job' ); // reload (pending may have advanced elsewhere)
	$job['done'][] = $res;
	update_option( 'mab_job', $job, false );
	delete_transient( 'mab_lock' );
	if ( ! empty( $job['pending'] ) ) { wp_schedule_single_event( time() + 1, 'mab_run_one' ); spawn_cron(); }
	return $res;
}
add_action( 'mab_run_one', 'mab_run_one_cb' );

add_action( 'wp_ajax_mab_run_one', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false ), 403 ); }
	$r = mab_run_one_cb();
	$job = get_option( 'mab_job' );
	wp_send_json( array( 'ok' => true, 'last' => $r,
		'pending' => is_array( $job ) ? count( $job['pending'] ) : 0,
		'done' => is_array( $job ) ? count( $job['done'] ) : 0 ) );
} );

add_action( 'wp_ajax_mab_status', function () {
	if ( ! current_user_can( 'edit_posts' ) ) { wp_send_json( array( 'ok' => false ), 403 ); }
	$job = get_option( 'mab_job' );
	if ( ! is_array( $job ) ) { wp_send_json( array( 'ok' => true, 'job' => null ) ); }
	wp_send_json( array( 'ok' => true, 'pending' => count( $job['pending'] ), 'done' => count( $job['done'] ),
		'results' => array_map( function ( $r ) {
			return array( 'id' => isset($r['id'])?$r['id']:0, 'committed' => ! empty( $r['committed'] ),
				'title' => isset($r['new_title'])?$r['new_title']:'', 'figs' => isset($r['figs'])?count($r['figs']):0,
				'error' => isset($r['error'])?$r['error']:null );
		}, $job['done'] ) ) );
} );

// ---- the actual per-article work (sideload figs, place [[FIGURE:n]], wp_update_post) ----
function mab_process_one( $base, $art ) {
	$id = intval( $art['id'] );
	$post = get_post( $id );
	if ( ! $post || $post->post_type !== 'mab_article' ) { return array( 'id' => $id, 'ok' => false, 'error' => 'post not found / wrong type' ); }
	$content = (string) ( isset( $art['body_html'] ) ? $art['body_html'] : '' );
	$figures = ( isset( $art['figures'] ) && is_array( $art['figures'] ) ) ? $art['figures'] : array();
	usort( $figures, function ( $a, $b ) { return ( isset( $a['n'] ) ? intval( $a['n'] ) : 999 ) - ( isset( $b['n'] ) ? intval( $b['n'] ) : 999 ); } );
	$fig_log = array(); $unplaced = array();
	foreach ( $figures as $fig ) {
		$file = isset( $fig['file'] ) ? $fig['file'] : ''; $num = isset( $fig['n'] ) ? intval( $fig['n'] ) : 0;
		$cap = isset( $fig['caption'] ) ? trim( (string) $fig['caption'] ) : '';
		if ( ! $file ) { continue; }
		$src_url = mab_existing_fig_url( $id, $file ); $att_id = 0;
		if ( ! $src_url ) {
			$att_id = media_sideload_image( $base . $file, $id, ( $cap !== '' ? $cap : null ), 'id' );
			if ( is_wp_error( $att_id ) ) { $fig_log[] = array( 'file' => $file, 'ok' => false, 'error' => $att_id->get_error_message() ); continue; }
			update_post_meta( $att_id, '_mab_fig_key', $file );
			$src_url = wp_get_attachment_url( $att_id );
		}
		$figHtml = mab_fig_html( $src_url, $cap );
		$ph = '[[FIGURE:' . $num . ']]';
		$pos = ( $num && strpos( $content, $ph ) !== false ) ? strpos( $content, $ph ) : false;
		if ( $pos !== false ) { $content = substr_replace( $content, $figHtml, $pos, strlen( $ph ) ); }
		else { $unplaced[] = $figHtml; }
		$fig_log[] = array( 'file' => $file, 'ok' => true, 'placed' => ( $pos !== false ) );
	}
	$content = preg_replace( '/\[\[FIGURE:\d+\]\]/', '', $content );
	if ( $unplaced ) { $content .= "\n" . implode( "\n", $unplaced ); }
	$content = trim( $content );
	$new_title = isset( $art['title'] ) ? trim( (string) $art['title'] ) : $post->post_title;
	$entry = array( 'id' => $id, 'ok' => true, 'new_title' => $new_title, 'figs' => $fig_log, 'committed' => false );
	$upd = wp_update_post( array( 'ID' => $id, 'post_title' => $new_title, 'post_content' => $content ), true );
	if ( is_wp_error( $upd ) ) { $entry['ok'] = false; $entry['error'] = $upd->get_error_message(); }
	else { $entry['committed'] = true; }
	return $entry;
}

function mab_existing_fig_url( $post_id, $file ) {
	$q = get_posts( array( 'post_type' => 'attachment', 'post_parent' => $post_id, 'post_status' => 'inherit',
		'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_mab_fig_key', 'meta_value' => $file ) );
	if ( $q ) { $u = wp_get_attachment_url( $q[0] ); if ( $u ) { return $u; } }
	return '';
}
function mab_fig_html( $url, $caption ) {
	$alt = $caption !== '' ? esc_attr( $caption ) : '';
	$html = '<figure class="mab-figure"><img src="' . esc_url( $url ) . '" alt="' . $alt . '" loading="lazy" />';
	if ( $caption !== '' ) { $html .= '<figcaption>' . esc_html( $caption ) . '</figcaption>'; }
	return $html . '</figure>';
}
