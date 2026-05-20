<?php
/**
 * Plugin Name: Lean Comparisons
 * Plugin URI:  https://github.com/ctala/lean-comparisons
 * Description: Programmatic SEO comparison pages for WordPress. CPT comparacion + reverse-linking to glosario CPT. Zero JS. No bloat.
 * Version:     1.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author:      Cristian Tala
 * Author URI:  https://cristiantala.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: lean-comparisons
 *
 * @package LeanComparisons
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LEAN_CMP_VERSION', '1.0.0' );
define( 'LEAN_CMP_NS', '_lean_cmp_' );

/*
 * Transient TTL for reverse-link cache (seconds).
 * 12 hours — comparisons are published infrequently; stale cache is acceptable.
 * Invalidated on save_post for any `comparacion` post (see lean_cmp_invalidate_cache).
 */
define( 'LEAN_CMP_CACHE_TTL', 43200 );

/* ═══════════════════════════════════════════════════════════════════════════
   CPT REGISTRATION
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'init', 'lean_cmp_register_cpt', 10 );

/**
 * Register the `comparacion` CPT.
 * REST-first: show_in_rest + rest_base so scripts can CRUD via WP REST API.
 *
 * @return void
 */
function lean_cmp_register_cpt() {
	$labels = array(
		'name'               => __( 'Comparaciones', 'lean-comparisons' ),
		'singular_name'      => __( 'Comparación', 'lean-comparisons' ),
		'add_new'            => __( 'Nueva comparación', 'lean-comparisons' ),
		'add_new_item'       => __( 'Agregar comparación', 'lean-comparisons' ),
		'edit_item'          => __( 'Editar comparación', 'lean-comparisons' ),
		'new_item'           => __( 'Nueva comparación', 'lean-comparisons' ),
		'view_item'          => __( 'Ver comparación', 'lean-comparisons' ),
		'search_items'       => __( 'Buscar comparaciones', 'lean-comparisons' ),
		'not_found'          => __( 'No hay comparaciones.', 'lean-comparisons' ),
		'not_found_in_trash' => __( 'No hay comparaciones en la papelera.', 'lean-comparisons' ),
	);

	register_post_type( 'comparacion', array(
		'labels'              => $labels,
		'public'              => true,
		'publicly_queryable'  => true,
		'show_ui'             => true,
		'show_in_menu'        => true,
		'show_in_rest'        => true,   // REQUIRED: enables Block Editor + REST API
		'rest_base'           => 'comparaciones',
		'menu_icon'           => 'dashicons-randomize',
		'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
		'has_archive'         => false,  // no archive page — we use sitemap only
		'rewrite'             => array(
			'slug'       => 'comparaciones',
			'with_front' => false,
		),
		'capability_type'     => 'post',
	) );
}

/* ═══════════════════════════════════════════════════════════════════════════
   META REGISTRATION
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'init', 'lean_cmp_register_meta', 20 );

/**
 * Register term_a and term_b relation meta keys.
 * show_in_rest=true is REQUIRED for the Python generation script to write via REST.
 *
 * @return void
 */
function lean_cmp_register_meta() {
	$shared = array(
		'show_in_rest'  => true,
		'single'        => true,
		'type'          => 'integer',
		'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
	);

	register_post_meta( 'comparacion', LEAN_CMP_NS . 'term_a_id', $shared );
	register_post_meta( 'comparacion', LEAN_CMP_NS . 'term_b_id', $shared );
}

/* ═══════════════════════════════════════════════════════════════════════════
   SCHEMA — inject ComparisonPage via lean_seo_jsonld_graph filter.
   If lean-seo is not active, fall back to standalone <script> injection.
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'wp_head', 'lean_cmp_maybe_inject_standalone_schema', 2 );

/**
 * Emit JSON-LD only if lean-seo is NOT handling the @graph for this post.
 * lean-seo fires on wp_head priority 1; we hook priority 2 to detect it.
 *
 * When lean-seo IS active we use lean_seo_jsonld_graph filter instead
 * (registered below), so this function exits early if lean-seo is loaded.
 *
 * @return void
 */
function lean_cmp_maybe_inject_standalone_schema() {
	// lean-seo already handles the @graph — nothing to do here.
	// The lean_seo_jsonld_graph filter below will add our node.
	if ( function_exists( 'lean_seo_emit_jsonld' ) ) {
		return;
	}

	if ( ! is_singular( 'comparacion' ) ) {
		return;
	}

	$post_id = get_queried_object_id();
	$schema  = lean_cmp_build_schema_node( $post_id );
	if ( ! $schema ) {
		return;
	}

	$doc = array(
		'@context' => 'https://schema.org',
		'@graph'   => array( $schema ),
	);
	echo '<script type="application/ld+json">'
		. wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		. '</script>' . "\n";
}

/**
 * Inject ComparisonPage node into lean-seo's @graph when active.
 * Filter signature matches lean_seo_emit_jsonld call at lean-seo.php:523.
 *
 * @param array  $graph   Existing graph nodes.
 * @param int    $post_id Post ID (0 if not singular).
 * @param string $url     Canonical URL.
 * @return array
 */
add_filter( 'lean_seo_jsonld_graph', 'lean_cmp_add_to_graph', 10, 3 );

function lean_cmp_add_to_graph( $graph, $post_id, $url ) {
	if ( ! $post_id || ! is_singular( 'comparacion' ) ) {
		return $graph;
	}

	$node = lean_cmp_build_schema_node( $post_id );
	if ( $node ) {
		$graph[] = $node;
	}

	return $graph;
}

/**
 * Tell lean-seo NOT to emit a generic Article node for comparacion posts.
 * We handle the primary schema ourselves with a ComparisonPage-like node.
 * Returning false suppresses lean-seo's Article node for this post type.
 *
 * @param string $default   lean-seo default type.
 * @param int    $post_id   Post ID.
 * @param string $post_type Post type.
 * @return string|false
 */
add_filter( 'lean_seo_default_article_type', 'lean_cmp_suppress_article_node', 10, 3 );

function lean_cmp_suppress_article_node( $default, $post_id, $post_type ) {
	if ( 'comparacion' === $post_type ) {
		return false; // false = lean-seo skips the Article node entirely
	}
	return $default;
}

/**
 * Build the schema.org node for a single comparacion post.
 * Uses schema.org/WebPage with @type "WebPage" and a name pattern.
 * No ComparisonPage type exists in schema.org as of 2026; WebPage is correct.
 *
 * @param int $post_id Post ID.
 * @return array|null
 */
function lean_cmp_build_schema_node( $post_id ) {
	$term_a_id = (int) get_post_meta( $post_id, LEAN_CMP_NS . 'term_a_id', true );
	$term_b_id = (int) get_post_meta( $post_id, LEAN_CMP_NS . 'term_b_id', true );

	$url  = get_permalink( $post_id );
	$name = get_the_title( $post_id );

	$node = array(
		'@type'       => 'WebPage',
		'@id'         => $url . '#comparacion',
		'name'        => $name,
		'url'         => $url,
		'description' => wp_strip_all_tags( get_the_excerpt( $post_id ) ),
	);

	// If both glosario terms are linked, add mentions for entity recognition.
	$mentions = array();
	foreach ( array( $term_a_id, $term_b_id ) as $tid ) {
		if ( $tid > 0 ) {
			$term_post = get_post( $tid );
			if ( $term_post && 'publish' === $term_post->post_status ) {
				$mentions[] = array(
					'@type' => 'DefinedTerm',
					'name'  => get_the_title( $tid ),
					'url'   => get_permalink( $tid ),
				);
			}
		}
	}
	if ( $mentions ) {
		$node['mentions'] = $mentions;
	}

	return $node;
}

/* ═══════════════════════════════════════════════════════════════════════════
   CONTENT FILTERS — reverse-link blocks appended via the_content
   ═══════════════════════════════════════════════════════════════════════════ */

add_filter( 'the_content', 'lean_cmp_append_related_on_comparacion', 20 );

/**
 * Append "Definiciones completas" block to comparacion posts.
 * Links back to both glosario entries — bidirectional internal linking.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function lean_cmp_append_related_on_comparacion( $content ) {
	if ( ! is_singular( 'comparacion' ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	$post_id   = get_the_ID();
	$term_a_id = (int) get_post_meta( $post_id, LEAN_CMP_NS . 'term_a_id', true );
	$term_b_id = (int) get_post_meta( $post_id, LEAN_CMP_NS . 'term_b_id', true );

	if ( ! $term_a_id && ! $term_b_id ) {
		return $content;
	}

	$links = array();
	foreach ( array( $term_a_id, $term_b_id ) as $tid ) {
		if ( $tid > 0 ) {
			$p = get_post( $tid );
			if ( $p && 'publish' === $p->post_status ) {
				$links[] = '<li><a href="' . esc_url( get_permalink( $tid ) ) . '">'
					. esc_html( get_the_title( $tid ) ) . '</a></li>';
			}
		}
	}

	if ( ! $links ) {
		return $content;
	}

	$block  = '<div class="lean-cmp-definitions">';
	$block .= '<h3>' . esc_html__( 'Definiciones completas', 'lean-comparisons' ) . '</h3>';
	$block .= '<ul>' . implode( "\n", $links ) . '</ul>';
	$block .= '</div>';

	return $content . "\n" . $block;
}

add_filter( 'the_content', 'lean_cmp_append_related_on_glosario', 20 );

/**
 * Append "Comparaciones relacionadas" block to glosario entries.
 * Uses transient cache (TTL: LEAN_CMP_CACHE_TTL) keyed by glosario post ID
 * to avoid re-running a meta query on every page view.
 *
 * Query: finds comparacion posts where term_a_id OR term_b_id == current glosario ID.
 * WP_Query with meta_query is the correct tool here — wp_postmeta has an index on
 * (post_id, meta_key, meta_value) which makes this a covered lookup, not a full scan.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function lean_cmp_append_related_on_glosario( $content ) {
	if ( ! is_singular( 'glosario' ) || ! is_main_query() || ! in_the_loop() ) {
		return $content;
	}

	$glosario_id = get_the_ID();
	$cache_key   = 'lean_cmp_related_' . $glosario_id;

	$related = get_transient( $cache_key );

	if ( false === $related ) {
		$related = lean_cmp_query_related_comparisons( $glosario_id );
		set_transient( $cache_key, $related, LEAN_CMP_CACHE_TTL );
	}

	if ( empty( $related ) ) {
		return $content;
	}

	$items = array();
	foreach ( $related as $cmp ) {
		$items[] = '<li><a href="' . esc_url( get_permalink( $cmp->ID ) ) . '">'
			. esc_html( get_the_title( $cmp->ID ) ) . '</a></li>';
	}

	$block  = '<div class="lean-cmp-related">';
	$block .= '<h3>' . esc_html__( 'Comparaciones relacionadas', 'lean-comparisons' ) . '</h3>';
	$block .= '<ul>' . implode( "\n", $items ) . '</ul>';
	$block .= '</div>';

	return $content . "\n" . $block;
}

/**
 * Run the meta query to find comparaciones referencing a given glosario post ID.
 * Called only when the transient is missing or expired.
 *
 * Returns max 10 comparisons — enough for the link block, bounded to avoid
 * edge cases on popular terms with many comparisons.
 *
 * @param int $glosario_id Post ID of the glosario entry.
 * @return WP_Post[]
 */
function lean_cmp_query_related_comparisons( $glosario_id ) {
	$q = new WP_Query( array(
		'post_type'      => 'comparacion',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'no_found_rows'  => true,   // skip SQL_CALC_FOUND_ROWS — we don't paginate
		'update_post_term_cache' => false,
		'meta_query'     => array(
			'relation' => 'OR',
			array(
				'key'     => LEAN_CMP_NS . 'term_a_id',
				'value'   => $glosario_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => LEAN_CMP_NS . 'term_b_id',
				'value'   => $glosario_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
		),
		'fields' => 'ids', // retrieve only IDs — no need for full WP_Post objects yet
	) );

	if ( empty( $q->posts ) ) {
		return array();
	}

	// Re-fetch as WP_Post objects with title/permalink data needed for the block.
	// get_posts() uses the object cache so these are cheap if already loaded.
	return array_filter( array_map( 'get_post', $q->posts ) );
}

/* ═══════════════════════════════════════════════════════════════════════════
   CACHE INVALIDATION — purge transient when a comparacion is saved/deleted
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'save_post_comparacion', 'lean_cmp_invalidate_cache', 10, 1 );
add_action( 'before_delete_post',    'lean_cmp_invalidate_cache_on_delete', 10, 1 );

/**
 * When a comparacion post is saved, invalidate the cached reverse-link block
 * for both referenced glosario terms so the change appears immediately.
 *
 * @param int $post_id Post ID of the saved comparacion.
 * @return void
 */
function lean_cmp_invalidate_cache( $post_id ) {
	foreach ( array( LEAN_CMP_NS . 'term_a_id', LEAN_CMP_NS . 'term_b_id' ) as $meta_key ) {
		$tid = (int) get_post_meta( $post_id, $meta_key, true );
		if ( $tid > 0 ) {
			delete_transient( 'lean_cmp_related_' . $tid );
		}
	}
}

/**
 * On delete, do the same invalidation before post meta is erased.
 *
 * @param int $post_id Post ID being deleted.
 * @return void
 */
function lean_cmp_invalidate_cache_on_delete( $post_id ) {
	if ( 'comparacion' !== get_post_type( $post_id ) ) {
		return;
	}
	lean_cmp_invalidate_cache( $post_id );
}

/* ═══════════════════════════════════════════════════════════════════════════
   ADMIN — meta box for selecting the two glosario terms
   ═══════════════════════════════════════════════════════════════════════════ */

add_action( 'add_meta_boxes', 'lean_cmp_add_meta_box' );

/**
 * Register the "Términos comparados" meta box.
 *
 * @return void
 */
function lean_cmp_add_meta_box() {
	add_meta_box(
		'lean_cmp_terms',
		__( 'Términos comparados', 'lean-comparisons' ),
		'lean_cmp_render_meta_box',
		'comparacion',
		'side',
		'high'
	);
}

/**
 * Render the meta box: two text inputs for glosario post IDs.
 * Using IDs (not a select) because 550 glosario entries would make a select unusable.
 * The Python generation script populates these via REST anyway — this is for manual edits.
 *
 * @param WP_Post $post Post being edited.
 * @return void
 */
function lean_cmp_render_meta_box( $post ) {
	wp_nonce_field( 'lean_cmp_save', 'lean_cmp_nonce' );

	$term_a_id = (int) get_post_meta( $post->ID, LEAN_CMP_NS . 'term_a_id', true );
	$term_b_id = (int) get_post_meta( $post->ID, LEAN_CMP_NS . 'term_b_id', true );

	$label_a = $term_a_id ? get_the_title( $term_a_id ) : '';
	$label_b = $term_b_id ? get_the_title( $term_b_id ) : '';

	echo '<style>.lean-cmp-row{margin:8px 0}.lean-cmp-row label{display:block;font-weight:600;margin-bottom:3px}.lean-cmp-row input{width:100%}.lean-cmp-hint{font-size:11px;color:#777;margin-top:2px}</style>';

	echo '<div class="lean-cmp-row">';
	echo '<label>' . esc_html__( 'Término A — ID glosario', 'lean-comparisons' ) . '</label>';
	echo '<input type="number" name="lean_cmp_term_a_id" value="' . esc_attr( $term_a_id ?: '' ) . '" min="1" />';
	if ( $label_a ) {
		echo '<div class="lean-cmp-hint">' . esc_html( $label_a ) . '</div>';
	}
	echo '</div>';

	echo '<div class="lean-cmp-row">';
	echo '<label>' . esc_html__( 'Término B — ID glosario', 'lean-comparisons' ) . '</label>';
	echo '<input type="number" name="lean_cmp_term_b_id" value="' . esc_attr( $term_b_id ?: '' ) . '" min="1" />';
	if ( $label_b ) {
		echo '<div class="lean-cmp-hint">' . esc_html( $label_b ) . '</div>';
	}
	echo '</div>';

	echo '<div class="lean-cmp-hint">' . esc_html__( 'Ingresa el ID numérico del CPT glosario. La URL generada será /glosario/{slug}.', 'lean-comparisons' ) . '</div>';
}

add_action( 'save_post', 'lean_cmp_save_meta_box', 10, 2 );

/**
 * Persist meta box values.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function lean_cmp_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['lean_cmp_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['lean_cmp_nonce'] ) ), 'lean_cmp_save' ) ) {
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( 'comparacion' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	foreach ( array( 'term_a_id', 'term_b_id' ) as $key ) {
		$value = isset( $_POST[ 'lean_cmp_' . $key ] ) ? absint( $_POST[ 'lean_cmp_' . $key ] ) : 0;
		if ( $value > 0 ) {
			update_post_meta( $post_id, LEAN_CMP_NS . $key, $value );
		} else {
			delete_post_meta( $post_id, LEAN_CMP_NS . $key );
		}
	}
}

/* ═══════════════════════════════════════════════════════════════════════════
   FLUSH REWRITE RULES on activation
   ═══════════════════════════════════════════════════════════════════════════ */

register_activation_hook( __FILE__, 'lean_cmp_activate' );

/**
 * Register CPT and flush rewrite rules on activation so /comparaciones/ works immediately.
 *
 * @return void
 */
function lean_cmp_activate() {
	lean_cmp_register_cpt();
	lean_cmp_register_meta();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'lean_cmp_deactivate' );

/**
 * Flush rewrite rules on deactivation.
 *
 * @return void
 */
function lean_cmp_deactivate() {
	flush_rewrite_rules();
}
