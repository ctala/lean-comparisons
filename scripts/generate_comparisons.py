#!/usr/bin/env python3
"""
generate_comparisons.py — pipeline pSEO para comparaciones del glosario de eco.

Para cada par (term_a, term_b):
  1. Lee las 2 entradas del CPT glosario vía WP REST (título + contenido + id)
  2. (LLM) genera el contenido de la comparación: intro + tabla diferencias +
     cuándo usar cada uno + FAQ — apoyado en las definiciones REALES (cero invención)
  3. Renderiza la featured image (Browserless, template eco brand)
  4. Sube la imagen como media WP + crea el post CPT `comparacion` vía REST con
     meta term_a_id/term_b_id + featured_media

Requiere (env):
  WORDPRESS_CTA_APP_PASSWORD_ECO   — App Password REST de eco (user:pass o token)
  BROWSERLESS_TOKEN                — token Browserless
  (LLM key según el backend que se conecte — ver generate_comparison_content)

USO:
  python3 generate_comparisons.py --dry-run        # genera imágenes + contenido, NO publica
  python3 generate_comparisons.py --pair "OKR,KPI" # un solo par
  python3 generate_comparisons.py                  # las 10 piloto (requiere plugin en PROD)

⚠️ El CPT `comparacion` debe existir en eco PROD (plugin lean-comparisons activo)
   antes de poder publicar. Sin él, usar --dry-run para pre-generar imágenes+contenido.
"""
import argparse, json, os, re, sys, base64, unicodedata, urllib.request, urllib.parse
from pathlib import Path

UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36"
ECO = "https://ecosistemastartup.com"
BROWSERLESS = "https://browser.cristiantala.com/screenshot"
TEMPLATE = Path(__file__).resolve().parent.parent / "templates" / "comparison-cover-template.html"
OUTDIR = Path("/tmp/comparisons-out")
OUTDIR.mkdir(exist_ok=True)

# Los 10 piloto: (term_a_id, term_a, term_b_id, term_b, meta_badge, subtitle)
# IDs confirmados contra el glosario (matcher alias index, 2026-05-20).
PILOT = [
    (None, "OKR", None, "KPI", "MÉTRICAS · GESTIÓN", "¿Cuál es la diferencia y cuándo usar cada uno?"),
    (None, "B2B", None, "B2C", "MODELO DE NEGOCIO", "Dos modelos, dos estrategias de venta distintas"),
    (None, "MRR", None, "ARR", "MÉTRICAS SAAS", "Ingresos recurrentes: mensual vs anual"),
    (None, "CAC", None, "LTV", "MÉTRICAS SAAS", "El ratio que define si tu negocio es viable"),
    (None, "Nota Convertible", None, "SAFE", "FUNDRAISING", "Dos instrumentos para levantar capital pre-valuación"),
    (None, "Pre-money", None, "Post-money", "FUNDRAISING", "Valuación antes y después de la inversión"),
    (None, "Churn Rate", None, "Retention Rate", "MÉTRICAS SAAS", "Las dos caras de la retención de clientes"),
    (None, "Seed Funding", None, "Pre-Seed", "FUNDRAISING", "Las primeras etapas de financiamiento"),
    (None, "Venture Capital", None, "Venture Debt", "FUNDRAISING", "Equity vs deuda para financiar tu startup"),
    (None, "Lean Startup", None, "Design Thinking", "METODOLOGÍA", "Dos enfoques para construir productos"),
]


def wp_get(path):
    req = urllib.request.Request(f"{ECO}/wp-json/wp/v2/{path}",
                                 headers={"User-Agent": UA, "Accept": "application/json"})
    return json.loads(urllib.request.urlopen(req, timeout=30).read())


def render_image(term_a, term_b, meta_badge, subtitle, out_path):
    tpl = TEMPLATE.read_text()
    html = (tpl.replace("__TERM_A__", term_a).replace("__TERM_B__", term_b)
               .replace("__META_BADGE__", meta_badge).replace("__SUBTITLE__", subtitle))
    token = os.environ["BROWSERLESS_TOKEN"]
    payload = {
        "html": html,
        "options": {"type": "jpeg", "quality": 90, "fullPage": False,
                    "clip": {"x": 0, "y": 0, "width": 1216, "height": 640}},
        "viewport": {"width": 1216, "height": 640},
        "gotoOptions": {"waitUntil": "networkidle0"},
    }
    req = urllib.request.Request(f"{BROWSERLESS}?token={token}",
                                 data=json.dumps(payload).encode(),
                                 headers={"Content-Type": "application/json"}, method="POST")
    img = urllib.request.urlopen(req, timeout=60).read()
    out_path.write_bytes(img)
    return out_path


def generate_comparison_content(term_a, defn_a, term_b, defn_b):
    """
    Genera el cuerpo HTML/Gutenberg de la comparación a partir de las definiciones REALES.
    Estructura fija: intro + tabla diferencias + cuándo usar cada uno + FAQ.

    PLACEHOLDER: conecta aquí tu LLM (Claude API / Ollama Cloud / OpenAI).
    Por ahora retorna un esqueleto con las definiciones reales embebidas para
    que el contenido NO se invente — el LLM solo reformula lo que ya existe.
    """
    # TODO: reemplazar por llamada LLM real. Prompt sugerido:
    #   "Eres editor de Ecosistema Startup. Compara {term_a} y {term_b} usando SOLO
    #    estas definiciones reales: [defn_a], [defn_b]. Estructura: intro 2 frases +
    #    tabla markdown de diferencias + cuándo usar cada uno + 3 FAQ. Español neutro
    #    LATAM. Cero invención. Sin promesas de marketing."
    raise NotImplementedError(
        "Conecta el LLM en generate_comparison_content() antes de publicar contenido. "
        "Para solo generar imágenes, usa --images-only.")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dry-run", action="store_true", help="genera imágenes + contenido, NO publica")
    ap.add_argument("--images-only", action="store_true", help="solo renderiza las imágenes piloto")
    ap.add_argument("--pair", help='un solo par "TermA,TermB"')
    args = ap.parse_args()

    if "BROWSERLESS_TOKEN" not in os.environ:
        sys.exit("Set BROWSERLESS_TOKEN")

    pairs = PILOT
    if args.pair:
        a, b = [x.strip() for x in args.pair.split(",")]
        pairs = [(None, a, None, b, "GLOSARIO", "¿Cuál es la diferencia?")]

    for _, ta, _, tb, meta, sub in pairs:
        slug = f"{slugify(ta)}-vs-{slugify(tb)}"
        out = OUTDIR / f"comparacion-{slug}.jpg"
        render_image(ta, tb, meta, sub, out)
        print(f"  ✓ imagen: {out}")

        if args.images_only:
            continue
        # contenido + publish (requiere LLM + plugin en PROD) — TODO
        if not args.dry_run:
            print(f"    [publish pendiente — requiere CPT comparacion en PROD + LLM]")

    print(f"\n{len(pairs)} imágenes en {OUTDIR}")


def slugify(s):
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", "-", s).strip("-")


if __name__ == "__main__":
    main()
