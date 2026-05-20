# Estándar de imágenes — Glosario + Comparaciones (Ecosistema Startup)

> **Creado:** 2026-05-20 · Aprobado por Cristian Tala
> **Aplica a:** featured images de entradas nuevas del CPT `glosario` + CPT `comparacion` en ecosistemastartup.com
> **Reemplaza:** el estándar viejo del glosario (fondo negro + título blanco + línea naranja simple)

Las 550 entradas viejas del glosario quedan con su imagen actual. Las **nuevas** (glosario + todas las comparaciones) usan este estándar — sube el nivel visual a la altura de los covers de cursos CAR, pero con **marca Ecosistema** (no CAR).

## Brand source (canónico)

Brand guides oficiales en `assets.nyx.cristiantala.com/brand/`:
- Eco: `/brand/ecosistema.html` ← este estándar
- ELHDA: `/brand/hora.html`
- Tala: `/brand/ctala.html`

## Especificación

| Atributo | Valor |
|---|---|
| **Dimensión** | `1216×640` px (consistencia con las 550 entradas glosario + theme eco) |
| **Formato** | JPEG quality 90 |
| **Naranja primary** | `#FF6600` |
| **Orange-dark** | `#E55A28` |
| **Accent (amarillo)** | `#FFB800` |
| **Gradient signature** | `linear-gradient(90deg, #FF6600, #FFB800, #FF6600)` (acento "VS" + accent-line) |
| **Background** | `linear-gradient(135deg, #14100c 0%, #1a1410 55%, #241405 100%)` (dark cálido) |
| **Display font** | `Bebas Neue` (títulos, términos) — NO JetBrains Mono (eso es CAR) |
| **Body font** | `Inter` (subtítulos, badges) |
| **Gray** | `#7F756F` |
| **Border-radius** | 8px (badges) — estilo eco, no 2-4px de CAR |
| **Footer** | co-branded: "ECOSISTEMA STARTUP" (izq, naranja) + "cristiantala.com" (der, gris) |
| **Decoración** | grid perspectiva (rgba naranja 0.045) + glow naranja + glow amarillo |

⚠️ **NUNCA mezclar con colores de marca CAR** (verde `#39ff14`, magenta) ni de Tala personal. Eco usa SOLO su paleta naranja/amarillo. Regla del brand guide eco: "Eco Orange NO mezclar con CAR en mismo visual".

## Templates

- `templates/comparison-cover-template.html` — para CPT `comparacion`. Variables: `__TERM_A__`, `__TERM_B__`, `__META_BADGE__` (categoría, ej "MÉTRICAS · GESTIÓN"), `__SUBTITLE__`.
- `templates/glossary-cover-template.html` — para entradas nuevas del CPT `glosario` (TBD — misma base, layout término único + "¿Qué es?").

## Naming convention

- Comparaciones: `comparacion-{term-a}-vs-{term-b}.jpg`
- Glosario nuevo: `que-es-{slug}.jpg` (mantiene patrón existente)
- Alt text comparación: `{Término A} vs {Término B} — Comparación · Glosario de Ecosistema Startup`

## Render

Browserless `/screenshot` endpoint (mismo pipeline que covers de cursos):
- URL: `https://browser.cristiantala.com/screenshot?token=...` (token en memory `reference_browserless_r2_nocodb.md`)
- viewport 1216×640, type jpeg, quality 90, clip exacto
- waitUntil networkidle0 (espera carga de Google Fonts)

## Storage

Sube a R2 `assets.cristiantala.com/...` O directo como featured media del post vía WP REST (`/wp-json/wp/v2/media` → set `featured_media` en el post). Para eco, subir como media del propio WP es lo más simple (queda en wp-content/uploads, mismo patrón que las 550 existentes).
