# Changelog — Lean Comparisons

All notable changes to this project will be documented in this file.
Format: [Semantic Versioning](https://semver.org/).

---

## [1.0.0] — 2026-05-20

### Added
- CPT `comparacion` with `rewrite slug=comparaciones`, `show_in_rest=true`, `rest_base=comparaciones`
- Post meta `_lean_cmp_term_a_id` and `_lean_cmp_term_b_id` registered with `register_post_meta` (`show_in_rest=true`, `type=integer`)
- Reverse-link block appended to `glosario` entries via `the_content` filter — lists comparisons referencing the current term
- Transient cache for reverse-link query (TTL: 12h), keyed by glosario post ID
- Cache invalidation on `save_post_comparacion` and `before_delete_post` for referenced terms
- "Definiciones completas" block appended to `comparacion` posts with links back to both glosario entries
- JSON-LD schema integration:
  - When lean-seo is active: injects a `WebPage` node with `mentions` via `lean_seo_jsonld_graph` filter; suppresses lean-seo's generic `Article` node via `lean_seo_default_article_type` filter (returns `false`)
  - When lean-seo is NOT active: emits a standalone `<script type="application/ld+json">` block at `wp_head` priority 2
- Admin meta box "Términos comparados" (side panel) with numeric inputs for term A/B IDs and title preview
- Activation hook flushes rewrite rules so `/comparaciones/` works immediately after install
- `uninstall.php` cleans all CPT posts, stray postmeta, and transients

### Architecture decisions
- No custom DB table — WP postmeta with a NUMERIC meta_query covers the reverse-link lookup efficiently (wp_postmeta has a composite index on post_id + meta_key + meta_value)
- No JS on frontend — admin meta box uses plain number inputs, no AJAX lookup
- LOC: ~310 (main file) + ~30 (uninstall) = ~340 total, well under 600 LOC budget
