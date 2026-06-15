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
    """Construct a clean, non-infringing product-box prompt for Nano Banana."""
    name     = meta.get("name", "Software")
    brand    = meta.get("brand", "")
    platform = (meta.get("platform") or "").lower()
    category = (meta.get("category") or "").lower()

    # Choose a box accent colour by category — keeps the storefront grid
    # visually coherent.
    if "office" in category or "office" in name.lower():
        accent = "orange and white"
    elif "windows" in category or "windows" in name.lower():
        accent = "deep blue and white"
    elif "project" in category or "project" in name.lower():
        accent = "emerald green and white"
    elif "visio" in category or "visio" in name.lower():
        accent = "indigo violet and white"
    elif "bitdefender" in name.lower():
        accent = "crimson red and graphite"
    elif "mcafee" in name.lower():
        accent = "vibrant red and graphite"
    elif "antivirus" in category or "antivirus" in name.lower():
        accent = "navy blue and white"
    else:
        accent = "modern blue and white"

    plat_text = ""
    if platform:
        plat_text = f" The platform '{platform.title()}' is subtly indicated on a side ribbon."

    return (
        f"Create a clean, modern software product box rendered as a 3D mock-up "
        f"on a soft neutral light-gray studio background with a subtle drop "
        f"shadow.  The box uses an {accent} colour scheme with a glossy "
        f"premium finish.  Use the generic, non-trademarked title "
        f"\"{name}\" in bold modern sans-serif on the box front.  "
        f"Include simple abstract geometric icons that suggest productivity "
        f"software (no real-brand logos, no Microsoft Windows flag, no Office "
        f"swirl) — just clean modern shapes.{plat_text}  Square 1:1 aspect, "
        f"centred composition, photographic lighting, no human figures, "
        f"no text other than the product name."
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
