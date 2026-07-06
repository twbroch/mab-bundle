<?php
/**
 * MH — Marathon & Beyond Archive UX
 * Hub at /mab/, single-article shell (meta header + related + issue nav),
 * issue term pages rendered as tables of contents, topic/author term pages as card grids.
 * WPCode: PHP snippet, Run Everywhere.
 */

/* ---------- helpers ---------- */

function mh_mab_page_start( $post_id ) {
	$p = get_post_meta( $post_id, 'mab_pages', true );
	if ( preg_match( '/(\d+)/', (string) $p, $m ) ) return (int) $m[1];
	return 9999;
}

function mh_mab_issue_parts( $name ) {
	// "Vol. 12, No. 6 (2008)" -> [12, 6, 2008]
	if ( preg_match( '/Vol\.\s*(\d+),\s*No\.\s*(\d+)\s*\((\d{4})\)/', $name, $m ) )
		return array( (int) $m[1], (int) $m[2], (int) $m[3] );
	return array( 0, 0, 0 );
}

// Real magazine covers exist for Vols 1-6 (all) plus a few early later issues.
// Uploaded to /wp-content/uploads/mab-cover-v{V}i{I}.jpg. Others get a branded tile.
function mh_mab_has_cover( $v, $i ) {
	if ( $v >= 1 && $v <= 6 ) return true;
	return in_array( $v . '-' . $i, array( '8-6', '9-2', '9-4', '10-1', '10-3', '10-4' ), true );
}
function mh_mab_cover_url( $v, $i ) {
	return mh_mab_has_cover( $v, $i )
		? content_url( '/uploads/mab-cover-v' . (int) $v . 'i' . (int) $i . '.jpg' )
		: '';
}

function mh_mab_reading_time( $post ) {
	$w = str_word_count( wp_strip_all_tags( $post->post_content ) );
	return max( 1, (int) round( $w / 230 ) );
}

function mh_mab_first_img( $post ) {
	if ( preg_match( '/<img[^>]+src="([^"]+)"/', $post->post_content, $m ) ) return $m[1];
	return '';
}

function mh_mab_terms( $post_id, $tax ) {
	$t = get_the_terms( $post_id, $tax );
	return ( $t && ! is_wp_error( $t ) ) ? $t : array();
}

function mh_mab_card( $post ) {
	$issue  = mh_mab_terms( $post->ID, 'mab_issue' );
	$author = mh_mab_terms( $post->ID, 'mab_author' );
	$year   = get_post_meta( $post->ID, 'mab_year', true );
	$img    = mh_mab_first_img( $post );
	$mins   = mh_mab_reading_time( $post );
	$ex     = wp_trim_words( wp_strip_all_tags( $post->post_content ), 26, '…' );
	$badge  = $issue ? preg_replace( '/\s*\(\d{4}\)/', '', $issue[0]->name ) : '';
	ob_start(); ?>
	<a class="mabc" href="<?php echo esc_url( get_permalink( $post ) ); ?>">
		<?php if ( $img ) : ?><span class="mabc-img" style="background-image:url('<?php echo esc_url( $img ); ?>')"></span>
		<?php else : ?><span class="mabc-img mabc-noimg"><span>M&amp;B</span></span><?php endif; ?>
		<span class="mabc-body">
			<span class="mabc-kicker"><?php echo esc_html( $badge ); ?><?php if ( $year ) echo ' · ' . esc_html( $year ); ?></span>
			<span class="mabc-title"><?php echo esc_html( get_the_title( $post ) ); ?></span>
			<span class="mabc-ex"><?php echo esc_html( $ex ); ?></span>
			<span class="mabc-meta"><?php echo $author ? esc_html( $author[0]->name ) . ' · ' : ''; ?><?php echo (int) $mins; ?> min read</span>
		</span>
	</a>
	<?php return ob_get_clean();
}

/* ---------- CSS ---------- */

add_action( 'wp_head', function () {
	if ( ! ( is_post_type_archive( 'mab_article' ) || is_singular( 'mab_article' )
		|| is_tax( 'mab_issue' ) || is_tax( 'mab_topic' ) || is_tax( 'mab_author' ) || is_tax( 'mab_type' ) ) ) return;
	?>
<style id="mh-mab-css">
body.post-type-archive-mab_article,body.single-mab_article,body.tax-mab_issue,body.tax-mab_topic,body.tax-mab_author,body.tax-mab_type{overflow-x:hidden}
.mab-wrap{max-width:1080px;margin:0 auto;padding:0 20px 60px;font-family:Lora,Georgia,serif;color:#1e2430}
.mab-hero{background:#141a26;color:#f4efe6;width:100vw;margin-left:calc(50% - 50vw);padding:52px calc(50vw - 50%) 44px;text-align:center;box-sizing:border-box}
.mab-hero .k{letter-spacing:.22em;font-size:12px;text-transform:uppercase;color:#e8a35c;font-family:Shuttleblock,system-ui,sans-serif}
.mab-hero h1{font-family:Shuttleblock,system-ui,sans-serif;font-size:clamp(30px,5vw,52px);margin:.25em 0 .2em;color:#fff}
.mab-hero p{max-width:640px;margin:0 auto;font-size:17px;line-height:1.6;color:#cfd4de}
.mab-hero .stats{margin-top:18px;font-size:13px;color:#8f97a6;letter-spacing:.06em}
.mab-search{max-width:520px;margin:26px auto 0;display:flex}
.mab-search input[type=search]{flex:1;padding:12px 16px;border:0;border-radius:6px 0 0 6px;font-size:15px}
.mab-search button{background:#EA7603;color:#fff;border:0;padding:12px 22px;border-radius:0 6px 6px 0;font-weight:700;cursor:pointer;font-family:Shuttleblock,system-ui,sans-serif}
.mab-sec{margin-top:44px}
.mab-sec>h2{font-family:Shuttleblock,system-ui,sans-serif;font-size:22px;border-bottom:3px solid #141a26;padding-bottom:8px;margin-bottom:18px}
.mab-sec>h2 small{float:right;font-size:13px;font-weight:400}
.mab-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:18px}
.mabc{display:flex;flex-direction:column;background:#fff;border:1px solid #e5e1d8;border-radius:10px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s}
.mabc:hover{box-shadow:0 8px 24px rgba(20,26,38,.12);transform:translateY(-2px)}
.mabc-img{display:block;height:150px;background-size:cover;background-position:center}
.mabc-noimg{display:flex;align-items:center;justify-content:center;background:repeating-linear-gradient(45deg,#141a26,#141a26 12px,#182034 12px,#182034 24px)}
.mabc-noimg span{font-family:Shuttleblock,system-ui,sans-serif;color:#e8a35c;font-size:26px;letter-spacing:.1em}
.mabc-body{display:flex;flex-direction:column;gap:6px;padding:14px 16px 16px}
.mabc-kicker{font-size:11px;letter-spacing:.14em;text-transform:uppercase;color:#b05a02;font-family:Shuttleblock,system-ui,sans-serif}
.mabc-title{font-size:18px;font-weight:700;line-height:1.3}
.mabc-ex{font-size:14px;line-height:1.5;color:#555c68}
.mabc-meta{font-size:12px;color:#8b8f98;margin-top:auto;padding-top:6px}
.mab-chips{display:flex;flex-wrap:wrap;gap:8px}
.mab-chips a{display:inline-block;background:#f4efe6;border:1px solid #e0d9c8;border-radius:999px;padding:7px 14px;font-size:13.5px;text-decoration:none;color:#1e2430;font-family:Shuttleblock,system-ui,sans-serif}
.mab-chips a b{color:#b05a02;font-weight:700}
.mab-chips a:hover{background:#141a26;color:#fff}
.mab-issues{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:14px}
.mab-issues a{display:flex;flex-direction:column;border:1px solid #e0d9c8;border-radius:8px;overflow:hidden;text-decoration:none;color:#1e2430;background:#fff;transition:box-shadow .15s,transform .15s}
.mab-issues a:hover{border-color:#EA7603;box-shadow:0 6px 18px rgba(20,26,38,.12);transform:translateY(-2px)}
.mab-issues .cov{width:100%;aspect-ratio:3/4;object-fit:cover;display:block;background:#f4efe6}
.mab-issues .ph{width:100%;aspect-ratio:3/4;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:7px;background:#141a26;color:#f4efe6;padding:12px;box-sizing:border-box}
.mab-issues .ph .w{font-family:Shuttleblock,system-ui,sans-serif;font-size:13px;font-weight:700;letter-spacing:.03em;line-height:1.15;color:#fff;border-bottom:2px solid #EA7603;padding-bottom:7px}
.mab-issues .ph .n{font-family:Shuttleblock,system-ui,sans-serif;font-size:12px;color:#e8a35c;letter-spacing:.05em}
.mab-issues .ph .y{font-size:12px;color:#9aa1b0}
.mab-issues .mt{padding:8px 11px 11px}
.mab-issues .vol{display:block;font-weight:700;font-family:Shuttleblock,system-ui,sans-serif;font-size:11.5px;letter-spacing:.04em;color:#b05a02}
.mab-issues .yr{font-size:12px;color:#8b8f98}
.mab-hero__cover{display:block;width:158px;max-width:42vw;height:auto;margin:0 auto 20px;border-radius:5px;box-shadow:0 12px 34px rgba(0,0,0,.4)}
/* single article */
.mab-metabar{background:#f4efe6;border:1px solid #e0d9c8;border-radius:10px;padding:14px 18px;margin:0 0 26px;font-size:14px;line-height:1.7;font-family:Shuttleblock,system-ui,sans-serif}
.mab-metabar .crumb{font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin-bottom:4px}
.mab-metabar .crumb a{color:#b05a02;text-decoration:none}
.mab-metabar b a{color:#1e2430;text-decoration:none}
.mab-metabar .chips{margin-top:6px}
.mab-metabar .chips a{display:inline-block;background:#fff;border:1px solid #e0d9c8;border-radius:999px;padding:2px 10px;font-size:12px;margin:2px 4px 0 0;text-decoration:none;color:#1e2430}
.single-mab_article .entry-content>p:first-of-type::first-letter,
.mab-body>p:first-of-type::first-letter{font-size:3.1em;font-family:Georgia,serif;float:left;line-height:.85;padding:4px 8px 0 0;color:#b05a02}
.single-mab_article .entry-content figure,.single-mab_article .entry-content img{border-radius:8px}
.single-mab_article figcaption{font-size:13px;color:#777;font-style:italic}
.mab-rails{margin-top:44px}
.mab-issuenav{display:flex;justify-content:space-between;gap:14px;margin-top:30px}
.mab-issuenav a{flex:1;border:1px solid #e0d9c8;border-radius:10px;padding:12px 16px;text-decoration:none;color:#1e2430;font-size:14px;background:#fff}
.mab-issuenav a span{display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:#b05a02;font-family:Shuttleblock,system-ui,sans-serif;margin-bottom:3px}
.mab-issuenav a.next{text-align:right}
.mab-cta{margin-top:40px;background:#141a26;border-radius:12px;color:#f4efe6;padding:28px 30px;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
.mab-cta h3{margin:0;font-family:Shuttleblock,system-ui,sans-serif;color:#fff;font-size:20px}
.mab-cta p{margin:4px 0 0;font-size:14px;color:#cfd4de}
.mab-cta a{background:#EA7603;color:#fff;text-decoration:none;font-weight:700;padding:11px 22px;border-radius:8px;font-family:Shuttleblock,system-ui,sans-serif;white-space:nowrap}
/* issue TOC */
.mab-toc{list-style:none;margin:0;padding:0}
.mab-toc li{border-bottom:1px solid #e5e1d8}
.mab-toc a{display:flex;gap:18px;align-items:baseline;padding:14px 6px;text-decoration:none;color:#1e2430}
.mab-toc a:hover{background:#faf7f0}
.mab-toc .pg{min-width:64px;font-family:Shuttleblock,system-ui,sans-serif;font-size:12px;color:#b05a02;letter-spacing:.05em}
.mab-toc .t{font-size:17px;font-weight:700;flex:1}
.mab-toc .a{font-size:13px;color:#8b8f98;white-space:nowrap}
.mab-pagination{margin-top:30px;text-align:center;font-family:Shuttleblock,system-ui,sans-serif}
.mab-pagination a,.mab-pagination span{display:inline-block;padding:8px 13px;border:1px solid #e0d9c8;border-radius:6px;margin:0 3px;text-decoration:none;color:#1e2430}
.mab-pagination .current{background:#141a26;color:#fff;border-color:#141a26}
.mab-backlink{display:inline-block;margin:22px 0 0;font-size:13px;font-family:Shuttleblock,system-ui,sans-serif;color:#b05a02;text-decoration:none}
@media(max-width:640px){.mab-hero{padding-top:36px;padding-bottom:32px}.mab-issuenav{flex-direction:column}}
</style>
	<?php
}, 99 );

/* ---------- router: hub + term archives ---------- */

add_action( 'template_redirect', function () {
	if ( is_post_type_archive( 'mab_article' ) && ! is_search() ) { mh_mab_render_hub(); exit; }
	if ( is_tax( 'mab_issue' ) )  { mh_mab_render_issue_toc(); exit; }
	if ( is_tax( 'mab_topic' ) || is_tax( 'mab_author' ) || is_tax( 'mab_type' ) ) { mh_mab_render_term_grid(); exit; }
} );

function mh_mab_render_hub() {
	get_header();
	$count  = wp_count_posts( 'mab_article' )->publish;
	$topics = get_terms( array( 'taxonomy' => 'mab_topic', 'orderby' => 'count', 'order' => 'DESC', 'number' => 28, 'hide_empty' => true ) );
	$authors = get_terms( array( 'taxonomy' => 'mab_author', 'orderby' => 'count', 'order' => 'DESC', 'number' => 12, 'hide_empty' => true ) );
	$issues = get_terms( array( 'taxonomy' => 'mab_issue', 'hide_empty' => true, 'number' => 0 ) );
	usort( $issues, function ( $a, $b ) {
		$pa = mh_mab_issue_parts( $a->name ); $pb = mh_mab_issue_parts( $b->name );
		return ( $pa[0] * 10 + $pa[1] ) <=> ( $pb[0] * 10 + $pb[1] );
	} );
	$featured_slugs = array( 'chicago-turns-25', 'duel-in-the-sun-revisited', 'the-10-most-important-marathons-in-history', 'the-man-of-steele-defies-science', 'south-pole-marathon-journal-to-the-pole', 'running-with-the-kenyans' );
	$featured = get_posts( array( 'post_type' => 'mab_article', 'post_name__in' => $featured_slugs, 'posts_per_page' => 6, 'orderby' => 'post_name__in' ) );
	$latest = get_posts( array( 'post_type' => 'mab_article', 'posts_per_page' => 6, 'orderby' => 'rand' ) );
	?>
	<div class="mab-wrap">
		<div class="mab-hero">
			<div class="k">From the pages of the legendary running journal</div>
			<h1>The Marathon &amp; Beyond Archive</h1>
			<p>Every issue of <em>Marathon &amp; Beyond</em> — the long-form magazine for marathoners and ultrarunners, published 1997&ndash;2015 — digitized, restored, and free to read.</p>
			<div class="stats"><?php echo number_format( $count ); ?> ARTICLES · <?php echo count( $issues ); ?> ISSUES · 19 VOLUMES</div>
			<form class="mab-search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
				<input type="search" name="s" placeholder="Search the archive — e.g. Boston, heat, Comrades…" />
				<input type="hidden" name="post_type" value="mab_article" />
				<button type="submit">Search</button>
			</form>
		</div>

		<?php if ( $featured ) : ?>
		<div class="mab-sec"><h2>Classics from the vault</h2>
			<div class="mab-grid"><?php foreach ( $featured as $p ) echo mh_mab_card( $p ); ?></div>
		</div>
		<?php endif; ?>

		<?php if ( $topics ) : ?>
		<div class="mab-sec"><h2>Browse by topic</h2>
			<div class="mab-chips"><?php foreach ( $topics as $t ) : ?>
				<a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?> <b><?php echo (int) $t->count; ?></b></a>
			<?php endforeach; ?></div>
		</div>
		<?php endif; ?>

		<?php if ( $authors ) : ?>
		<div class="mab-sec"><h2>Voices of the magazine</h2>
			<div class="mab-chips"><?php foreach ( $authors as $t ) : if ( 'Editorial Staff' === $t->name ) continue; ?>
				<a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php echo esc_html( $t->name ); ?> <b><?php echo (int) $t->count; ?></b></a>
			<?php endforeach; ?></div>
		</div>
		<?php endif; ?>

		<div class="mab-sec"><h2>Something different <small>a random dip into 19 years</small></h2>
			<div class="mab-grid"><?php foreach ( $latest as $p ) echo mh_mab_card( $p ); ?></div>
		</div>

		<?php if ( $issues ) : ?>
		<div class="mab-sec"><h2>The complete run, issue by issue</h2>
			<div class="mab-issues"><?php foreach ( $issues as $t ) : $pp = mh_mab_issue_parts( $t->name ); $cu = mh_mab_cover_url( $pp[0], $pp[1] ); ?>
				<a href="<?php echo esc_url( get_term_link( $t ) ); ?>"><?php if ( $cu ) : ?><img class="cov" src="<?php echo esc_url( $cu ); ?>" alt="Marathon &amp; Beyond, Vol. <?php echo (int) $pp[0]; ?>, No. <?php echo (int) $pp[1]; ?> cover" loading="lazy" /><?php else : ?><span class="ph"><span class="w">Marathon &amp; Beyond</span><span class="n">Vol. <?php echo (int) $pp[0]; ?> · No. <?php echo (int) $pp[1]; ?></span><span class="y"><?php echo (int) $pp[2]; ?></span></span><?php endif; ?><span class="mt"><span class="vol">Vol. <?php echo (int) $pp[0]; ?> · No. <?php echo (int) $pp[1]; ?></span><span class="yr"><?php echo (int) $pp[2]; ?> — <?php echo (int) $t->count; ?> articles</span></span></a>
			<?php endforeach; ?></div>
		</div>
		<?php endif; ?>
	</div>
	<?php
	get_footer();
}

function mh_mab_render_issue_toc() {
	get_header();
	$term = get_queried_object();
	$posts = get_posts( array( 'post_type' => 'mab_article', 'posts_per_page' => 50,
		'tax_query' => array( array( 'taxonomy' => 'mab_issue', 'field' => 'term_id', 'terms' => $term->term_id ) ) ) );
	usort( $posts, function ( $a, $b ) { return mh_mab_page_start( $a->ID ) <=> mh_mab_page_start( $b->ID ); } );
	$pp = mh_mab_issue_parts( $term->name );
	$month = $posts ? get_post_meta( $posts[0]->ID, 'mab_month', true ) : '';
	?>
	<div class="mab-wrap">
		<div class="mab-hero">
			<div class="k">Marathon &amp; Beyond · <?php echo esc_html( trim( $month . ' ' . $pp[2] ) ); ?></div><?php $hcu = mh_mab_cover_url( $pp[0], $pp[1] ); if ( $hcu ) : ?><img class="mab-hero__cover" src="<?php echo esc_url( $hcu ); ?>" alt="Marathon &amp; Beyond cover" /><?php endif; ?>
			<h1><?php echo esc_html( $term->name ); ?></h1>
			<p><?php echo count( $posts ); ?> articles from this issue, in the order they appeared.</p>
		</div>
		<div class="mab-sec">
			<ul class="mab-toc"><?php foreach ( $posts as $p ) :
				$author = mh_mab_terms( $p->ID, 'mab_author' );
				$pages  = get_post_meta( $p->ID, 'mab_pages', true ); ?>
				<li><a href="<?php echo esc_url( get_permalink( $p ) ); ?>">
					<span class="pg"><?php echo $pages ? 'p. ' . esc_html( $pages ) : ''; ?></span>
					<span class="t"><?php echo esc_html( get_the_title( $p ) ); ?></span>
					<span class="a"><?php echo $author ? esc_html( $author[0]->name ) : ''; ?> · <?php echo mh_mab_reading_time( $p ); ?> min</span>
				</a></li>
			<?php endforeach; ?></ul>
			<a class="mab-backlink" href="<?php echo esc_url( get_post_type_archive_link( 'mab_article' ) ); ?>">&larr; All issues &amp; the full archive</a>
		</div>
	</div>
	<?php
	get_footer();
}

function mh_mab_render_term_grid() {
	get_header();
	$term  = get_queried_object();
	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$q = new WP_Query( array( 'post_type' => 'mab_article', 'posts_per_page' => 24, 'paged' => $paged,
		'tax_query' => array( array( 'taxonomy' => $term->taxonomy, 'field' => 'term_id', 'terms' => $term->term_id ) ) ) );
	$kind = ( 'mab_author' === $term->taxonomy ) ? 'Articles by' : 'Topic';
	?>
	<div class="mab-wrap">
		<div class="mab-hero">
			<div class="k">Marathon &amp; Beyond Archive · <?php echo esc_html( $kind ); ?></div>
			<h1><?php echo esc_html( $term->name ); ?></h1>
			<p><?php echo (int) $q->found_posts; ?> articles<?php echo $term->description ? ' — ' . esc_html( $term->description ) : ''; ?></p>
		</div>
		<div class="mab-sec">
			<div class="mab-grid"><?php foreach ( $q->posts as $p ) echo mh_mab_card( $p ); ?></div>
			<div class="mab-pagination"><?php
				echo paginate_links( array( 'total' => $q->max_num_pages, 'current' => $paged, 'prev_text' => '&larr;', 'next_text' => '&rarr;' ) );
			?></div>
			<a class="mab-backlink" href="<?php echo esc_url( get_post_type_archive_link( 'mab_article' ) ); ?>">&larr; Back to the full archive</a>
		</div>
	</div>
	<?php
	get_footer();
}

/* ---------- single article shell ---------- */

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'mab_article' ) || ! in_the_loop() || ! is_main_query() ) return $content;
	$post   = get_post();
	$issue  = mh_mab_terms( $post->ID, 'mab_issue' );
	$author = mh_mab_terms( $post->ID, 'mab_author' );
	$topics = mh_mab_terms( $post->ID, 'mab_topic' );
	$year   = get_post_meta( $post->ID, 'mab_year', true );
	$month  = get_post_meta( $post->ID, 'mab_month', true );
	$pages  = get_post_meta( $post->ID, 'mab_pages', true );
	$mins   = mh_mab_reading_time( $post );
	$hub    = get_post_type_archive_link( 'mab_article' );

	$bar  = '<div class="mab-metabar">';
	$bar .= '<div class="crumb"><a href="' . esc_url( $hub ) . '">M&amp;B Archive</a>';
	if ( $issue ) $bar .= ' / <a href="' . esc_url( get_term_link( $issue[0] ) ) . '">' . esc_html( $issue[0]->name ) . '</a>';
	$bar .= '</div>';
	$bar .= '<div>';
	if ( $author ) $bar .= 'By <b><a href="' . esc_url( get_term_link( $author[0] ) ) . '">' . esc_html( $author[0]->name ) . '</a></b> · ';
	$bar .= esc_html( trim( $month . ' ' . $year ) );
	if ( $pages ) $bar .= ' · pp. ' . esc_html( $pages );
	$bar .= ' · ' . (int) $mins . ' min read</div>';
	if ( $topics ) {
		$bar .= '<div class="chips">';
		foreach ( array_slice( $topics, 0, 6 ) as $t )
			$bar .= '<a href="' . esc_url( get_term_link( $t ) ) . '">' . esc_html( $t->name ) . '</a>';
		$bar .= '</div>';
	}
	$bar .= '</div>';

	$after = '';

	/* prev / next within the issue */
	if ( $issue ) {
		$sibs = get_posts( array( 'post_type' => 'mab_article', 'posts_per_page' => 50,
			'tax_query' => array( array( 'taxonomy' => 'mab_issue', 'field' => 'term_id', 'terms' => $issue[0]->term_id ) ) ) );
		usort( $sibs, function ( $a, $b ) { return mh_mab_page_start( $a->ID ) <=> mh_mab_page_start( $b->ID ); } );
		$idx = -1;
		foreach ( $sibs as $i => $s ) if ( $s->ID === $post->ID ) { $idx = $i; break; }
		$nav = '';
		if ( $idx > 0 ) {
			$pv = $sibs[ $idx - 1 ];
			$nav .= '<a href="' . esc_url( get_permalink( $pv ) ) . '"><span>&larr; Previous in this issue</span>' . esc_html( get_the_title( $pv ) ) . '</a>';
		}
		if ( $idx > -1 && $idx < count( $sibs ) - 1 ) {
			$nx = $sibs[ $idx + 1 ];
			$nav .= '<a class="next" href="' . esc_url( get_permalink( $nx ) ) . '"><span>Next in this issue &rarr;</span>' . esc_html( get_the_title( $nx ) ) . '</a>';
		}
		if ( $nav ) $after .= '<div class="mab-issuenav">' . $nav . '</div>';
	}

	/* related by topic */
	if ( $topics ) {
		$rel = get_posts( array( 'post_type' => 'mab_article', 'posts_per_page' => 3, 'post__not_in' => array( $post->ID ), 'orderby' => 'rand',
			'tax_query' => array( array( 'taxonomy' => 'mab_topic', 'field' => 'term_id', 'terms' => wp_list_pluck( $topics, 'term_id' ) ) ) ) );
		if ( $rel ) {
			$after .= '<div class="mab-rails mab-sec"><h2>More like this</h2><div class="mab-grid">';
			foreach ( $rel as $p ) $after .= mh_mab_card( $p );
			$after .= '</div></div>';
		}
	}

	$after .= '<div class="mab-cta"><div><h3>1,000+ stories from 19 years of Marathon &amp; Beyond</h3><p>The complete archive of the legendary long-distance journal — digitized and free to read.</p></div><a href="' . esc_url( $hub ) . '">Explore the archive</a></div>';

	return $bar . '<div class="mab-body">' . $content . '</div>' . $after;
}, 30 );
