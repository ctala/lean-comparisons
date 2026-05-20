<?php
/**
 * Uninstall handler for Lean Comparisons.
 *
 * Removes ALL plugin data:
 *   - All `comparacion` posts and their postmeta (via WP internal delete cascade)
 *   - _lean_cmp_* postmeta on any post type (defensive: covers any stray meta)
 *   - All lean_cmp_related_* transients
 *
 * This runs once when the plugin is deleted from wp-admin → Plugins.
 * It does NOT run on deactivation — only on uninstall.
 *
 * @package LeanComparisons
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// 1. Delete all `comparacion` posts (WP cascades deletion of their postmeta).
$comparacion_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'comparacion'" );
foreach ( $comparacion_ids as $post_id ) {
	wp_delete_post( (int) $post_id, true ); // $force_delete = true: bypass trash
}

// 2. Delete any stray _lean_cmp_* meta that may exist on other post types.
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_lean\_cmp\_%'"
);

// 3. Delete all lean_cmp_related_* transients.
// WP stores transients as _transient_{name} in wp_options.
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_lean\_cmp\_%' OR option_name LIKE '\_transient\_timeout\_lean\_cmp\_%'"
);
