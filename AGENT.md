# AGENT.md — Lean Comparisons

Context for Claude Code (Lean WP Plugin Architect agent). Read before touching this plugin.

## What this plugin does

Single-responsibility: manage the `comparacion` CPT + bidirectional internal linking between comparaciones and the `glosario` CPT on ecosistemastartup.com.

Does NOT handle: SEO meta (lean-seo), redirects (lean-redirects), CTAs (lean-ctas), auto-linking (lean-autolinks).

## Key decisions

### Schema: WebPage not ComparisonPage
schema.org has no `ComparisonPage` type as of 2026. `WebPage` with `mentions` pointing to both `DefinedTerm` nodes is the correct approach. Do not invent types.

### Schema integration pattern with lean-seo
Two hooks:
1. `lean_seo_default_article_type` → returns `false` for `comparacion` posts. This suppresses lean-seo's generic `Article` node (see lean-seo.php:464 — `if ( false !== $type )` guard).
2. `lean_seo_jsonld_graph` → adds our `WebPage` node to the shared @graph.

If lean-seo is deactivated, `lean_cmp_maybe_inject_standalone_schema()` fires at `wp_head` priority 2 and emits a standalone JSON-LD block. The check `function_exists('lean_seo_emit_jsonld')` is the lean-seo feature detection flag.

### Transient cache strategy
Cache key: `lean_cmp_related_{glosario_post_id}`. TTL: 43200s (12h).
Invalidated on: `save_post_comparacion` + `before_delete_post` for the affected glosario terms.

WHY 12h: comparisons are published in batch via script, not in real-time. A stale cache for half a day is acceptable. If you need real-time visibility after bulk ingestion, call `delete_transient('lean_cmp_related_' . $glosario_id)` from the Python script after publishing each batch.

### Meta query performance
WP_Query with `meta_query` OR on two keys (`term_a_id` / `term_b_id`). MySQL uses the `wp_postmeta` index `(meta_key, meta_value)`. With 550 glosario terms × N comparisons, this is a bounded, indexed lookup — NOT a full table scan. `no_found_rows=true` skips SQL_CALC_FOUND_ROWS. `fields='ids'` avoids loading full WP_Post objects in the query phase.

### Admin meta box: number inputs, no select
550 glosario entries → a `<select>` with 550 options is unusable. The primary creation path is the Python script via WP REST API; the meta box is for manual overrides only.

### No archive page
`has_archive=false`. Comparisons live under `/comparaciones/{slug}/` but there's no index at `/comparaciones/`. If you add an archive later, set `has_archive=true` and flush rewrites.

## Gotchas

### Rewrite rules must be flushed after install
The activation hook calls `flush_rewrite_rules()`. If you install manually via SFTP (not admin upload), run `wp rewrite flush` via WP-CLI after uploading.

### REST meta on `integer` type
`register_post_meta` with `type=integer` means REST expects an integer, not a string. The Python script must send `{ "_lean_cmp_term_a_id": 1234 }` (no quotes). If sent as string, WP REST will coerce but it's cleaner to be explicit.

### Cache invalidation does NOT cover bulk REST updates
If you publish 500 comparisons via the Python script in one session, the glosario transients for referenced terms will be invalidated one by one via `save_post_comparacion`. This is correct but means the first page load after the script runs will do a fresh query per glosario term (and rebuild the transient). Not a problem — it's a one-time warming cost.

### lean-seo detection via function_exists
`lean_seo_emit_jsonld` is defined in lean-seo.php at file scope (not inside a class). If lean-seo changes its architecture (class-based), update the detection check.

## Python script pattern for bulk creation

```python
import requests, os

base    = "https://ecosistemastartup.com/wp-json"
auth    = ("admin", os.environ["WP_APP_PASSWORD"])
headers = {"Content-Type": "application/json"}

payload = {
    "title":  "MVP vs Prototipo",
    "slug":   "mvp-vs-prototipo",
    "status": "publish",
    "content": "<p>Contenido de la comparación...</p>",
    "meta": {
        "_lean_cmp_term_a_id": 1234,   # ID del post glosario "MVP"
        "_lean_cmp_term_b_id": 5678,   # ID del post glosario "Prototipo"
    }
}

r = requests.post(f"{base}/comparaciones/v1/comparaciones", json=payload, auth=auth, headers=headers)
# NOTE: use WP REST endpoint, not plugin's own namespace:
# POST /wp-json/wp/v2/comparaciones  (rest_base='comparaciones', show_in_rest=true)
```

Correct endpoint: `POST /wp-json/wp/v2/comparaciones` (standard WP REST, not a custom namespace).

## Roadmap / open decisions

- [ ] Archive page at `/comparaciones/` — list of all comparisons for internal linking depth
- [ ] `lean_cmp_comparaciones_per_glosario` limit: currently 10. May need to raise for high-degree terms.
- [ ] Shortcode `[lean_cmp_related]` as alternative to the_content filter for themes that override content rendering
- [ ] Admin column in post list showing linked term titles (QoL for manual review)
- [ ] Consider `DefinedTermSet` schema node linking all glosario terms — belongs in lean-seo not here
