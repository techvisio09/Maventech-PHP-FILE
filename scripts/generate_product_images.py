#!/usr/bin/env python3
"""
Bulk product-image generator.

Reads /tmp/img_remap.json (a dict source_url → {slug, name, brand, category, platform})
and asks Gemini Nano Banana for one clean product mockup per unique source URL.
The generated PNG is saved at /app/php-version/uploads/products/<slug>.png and
a JSON map (source_url → internal_url) is written to /tmp/img_remap_done.json
so the calling PHP script can run a single bulk UPDATE.

Design choices:
- Generic, non-copyright-protected box-shot style prompts (Microsoft logo /
  brand artwork is described abstractly — no real-brand likeness).
- 1024x1024 PNGs, neutral light backdrop, soft shadow, photographic feel.
- Sequential calls with a small pause between them so we don't burst the API.
"""
import asyncio, base64, json, os, sys, time
from pathlib import Path
from dotenv import load_dotenv

load_dotenv("/app/backend/.env")

from emergentintegrations.llm.chat import LlmChat, UserMessage  # noqa: E402

API_KEY     = os.getenv("EMERGENT_LLM_KEY")
OUT_DIR     = Path("/app/php-version/uploads/products")
REMAP_IN    = Path("/tmp/img_remap.json")
REMAP_OUT   = Path("/tmp/img_remap_done.json")
INTERNAL_PREFIX = "/uploads/products"

OUT_DIR.mkdir(parents=True, exist_ok=True)
assert API_KEY, "EMERGENT_LLM_KEY missing in /app/backend/.env"


def build_prompt(meta: dict) -> str:
    """Construct a clean, non-infringing product-box prompt for Nano Banana.

    Palette tuned to match the storefront's dark-navy + cyan/teal theme —
    no bright/bold colours, no neon yellow, no harsh primary red.  Boxes
    feel like premium minimalist software packaging on a graphite studio
    backdrop, with subtle accent gradients pulled from the brand.
    """
    name     = meta.get("name", "Software")
    brand    = meta.get("brand", "")
    platform = (meta.get("platform") or "").lower()
    category = (meta.get("category") or "").lower()

    # Sophisticated, dark-theme-friendly accent per category.  Each one
    # pairs a deep base tone with a muted secondary — no fluorescent or
    # high-saturation hues — so the storefront grid stays cohesive.
    if "office" in category or "office" in name.lower():
        accent = "deep slate navy base with a muted burnt-amber accent strip"
    elif "windows" in category or "windows" in name.lower():
        accent = "midnight indigo base with a soft sky-blue accent ribbon"
    elif "project" in category or "project" in name.lower():
        accent = "graphite black base with a muted sage-emerald accent strip"
    elif "visio" in category or "visio" in name.lower():
        accent = "deep slate base with a dusty violet accent ribbon"
    elif "bitdefender" in name.lower():
        accent = "graphite charcoal base with a muted crimson accent strip"
    elif "mcafee" in name.lower():
        accent = "near-black graphite base with a muted brick-red accent ribbon"
    elif "antivirus" in category or "antivirus" in name.lower():
        accent = "deep navy base with a muted teal accent strip"
    else:
        accent = "deep slate navy base with a muted cyan accent strip"

    plat_text = ""
    if platform:
        plat_text = f" The platform '{platform.title()}' is subtly indicated on a side ribbon."

    return (
        f"Create a clean, modern software product-box rendered as a 3D mock-up "
        f"on a soft dark-graphite studio backdrop with a subtle teal-tinted "
        f"rim light and a gentle floor reflection.  The box uses a {accent} "
        f"and has a premium matte finish (NOT glossy, NOT bright, NOT yellow, "
        f"NOT neon).  The colour palette must feel sophisticated and muted — "
        f"avoid saturated primary colours; use deep, slightly desaturated "
        f"tones that would pair well with a navy + cyan dark-mode website. "
        f"Use the generic, non-trademarked title \"{name}\" in bold modern "
        f"sans-serif on the box front in soft warm white.  Include simple "
        f"abstract geometric icons that suggest productivity software (no "
        f"real-brand logos, no Microsoft Windows flag, no Office swirl) — "
        f"just clean modern shapes in white at 70-percent opacity.{plat_text}  "
        f"Square 1:1 aspect, centred composition, cinematic studio lighting, "
        f"no human figures, no text other than the product name."
    )


async def generate_one(source_url: str, meta: dict, retries: int = 2) -> str | None:
    """Generate ONE image, save to disk, return the internal URL."""
    slug = meta["slug"]
    out_path = OUT_DIR / f"{slug}.png"
    if out_path.exists() and out_path.stat().st_size > 5000:
        # Idempotent — skip already-generated files.
        return f"{INTERNAL_PREFIX}/{slug}.png"

    prompt = build_prompt(meta)
    for attempt in range(retries + 1):
        try:
            chat = (
                LlmChat(
                    api_key=API_KEY,
                    session_id=f"product-img-{slug}-{int(time.time())}",
                    system_message="You generate clean product mock-up images.",
                )
                .with_model("gemini", "gemini-3.1-flash-image-preview")
                .with_params(modalities=["image", "text"])
            )
            text, images = await chat.send_message_multimodal_response(
                UserMessage(text=prompt)
            )
            if not images:
                print(f"  [{slug}] no image returned (attempt {attempt+1}): {text[:80]}")
                await asyncio.sleep(1.0)
                continue
            data = base64.b64decode(images[0]["data"])
            out_path.write_bytes(data)
            return f"{INTERNAL_PREFIX}/{slug}.png"
        except Exception as e:
            print(f"  [{slug}] error attempt {attempt+1}: {e}")
            await asyncio.sleep(1.5)
    return None


async def main():
    if not REMAP_IN.exists():
        print(f"Missing {REMAP_IN}", file=sys.stderr)
        sys.exit(1)
    remap = json.loads(REMAP_IN.read_text())
    print(f"Generating {len(remap)} unique product images …")
    done = {}
    for i, (src, meta) in enumerate(remap.items(), 1):
        slug = meta["slug"]
        print(f"  [{i}/{len(remap)}] {slug}")
        internal = await generate_one(src, meta)
        if internal:
            done[src] = internal
        else:
            print(f"  [{i}/{len(remap)}] FAILED → leaving as-is")
        # Tiny pause so we don't burst the API
        await asyncio.sleep(0.5)
    REMAP_OUT.write_text(json.dumps(done, indent=2))
    print(f"\nDone. {len(done)}/{len(remap)} succeeded. Map saved to {REMAP_OUT}")


if __name__ == "__main__":
    asyncio.run(main())
