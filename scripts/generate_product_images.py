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
    """Construct a "real-retail-card" product image prompt for Nano Banana.

    Matches the official Microsoft retail card style (e.g. Microsoft Office
    Home 2024): pure white background, the four-coloured Microsoft square
    logo in the upper-left, the product name in deep blue, three to four
    large rounded-square application tiles below in their official colours,
    and a slim specs strip at the bottom ("X User · Single/Multi Device").
    No 3D box mock-up — a FLAT, modern, photoshop-style product card.
    """
    name     = (meta.get("name") or "Software").strip()
    brand    = (meta.get("brand") or "").strip()
    platform = (meta.get("platform") or "").strip().lower()
    category = (meta.get("category") or "").strip().lower()
    apps_raw = (meta.get("apps") or "").strip().lower()

    name_lower = name.lower()

    # --- Determine the brand block ---
    if "office" in name_lower or "office" in category or "word" in name_lower or "excel" in name_lower or "powerpoint" in name_lower or "outlook" in name_lower or "access" in name_lower:
        brand_block = (
            "In the upper-left corner show the official Microsoft logo (four "
            "solid coloured squares — top-left red, top-right green, "
            "bottom-left blue, bottom-right yellow — arranged in a 2x2 grid) "
            "next to the dark-grey word \"Microsoft Office\" in a clean "
            "Segoe UI style font."
        )
    elif "windows" in name_lower or "windows" in category:
        brand_block = (
            "In the upper-left corner show the official four-pane Microsoft "
            "Windows logo (four blue squares arranged in a slightly-tilted "
            "perspective) next to the dark-grey word \"Microsoft\" in a "
            "clean Segoe UI style font."
        )
    elif "project" in name_lower or "project" in category:
        brand_block = (
            "In the upper-left corner show the official Microsoft logo "
            "(four coloured squares) next to the dark-grey word "
            "\"Microsoft Project\" in a clean Segoe UI style font."
        )
    elif "visio" in name_lower or "visio" in category:
        brand_block = (
            "In the upper-left corner show the official Microsoft logo "
            "(four coloured squares) next to the dark-grey word "
            "\"Microsoft Visio\" in a clean Segoe UI style font."
        )
    elif "bitdefender" in name_lower:
        brand_block = (
            "In the upper-left corner show a stylised crimson-red shield "
            "icon with a small white wolf silhouette inside, next to the "
            "dark-grey word \"Bitdefender\" in a modern sans-serif font."
        )
    elif "mcafee" in name_lower:
        brand_block = (
            "In the upper-left corner show a bold crimson-red rounded "
            "shield with a small white check-mark inside, next to the "
            "dark-grey word \"McAfee\" in a modern sans-serif font."
        )
    else:
        brand_block = (
            "In the upper-left corner show a clean modern brand monogram in "
            "deep navy next to the product brand name in a clean sans-serif "
            "font."
        )

    # --- Determine the title (the BIG centre text — strip brand prefix so
    #     the card emphasises the edition + year, like the real packaging) ---
    title = name
    for prefix in ("Microsoft Office ", "Microsoft "):
        if title.startswith(prefix):
            title = title[len(prefix):]
            break
    # Drop trailing "(Windows)" / "(Mac)" — the platform is shown elsewhere.
    title = title.replace(" (Windows)", "").replace(" (PC)", "").replace(" (Mac)", "").strip()
    # Keep it short for legibility — max ~26 chars.
    if len(title) > 30:
        title = title[:30].rstrip() + "…"

    # --- Determine the app tiles (only Office/suite products show them) ---
    tile_block = ""
    if "office" in name_lower and any(a in apps_raw for a in ("word", "excel", "powerpoint", "outlook", "access")):
        tiles = []
        if "word" in apps_raw:
            tiles.append('a large rounded-square tile with a soft blue gradient and a bold white "W" letter inside (Microsoft Word)')
        if "excel" in apps_raw:
            tiles.append('a large rounded-square tile with a soft green gradient and a bold white "X" letter inside (Microsoft Excel)')
        if "powerpoint" in apps_raw:
            tiles.append('a large rounded-square tile with a soft red/coral gradient and a bold white "P" letter inside (Microsoft PowerPoint)')
        if "outlook" in apps_raw:
            tiles.append('a large rounded-square tile with a soft blue gradient and a bold white "O" letter inside (Microsoft Outlook)')
        if "access" in apps_raw:
            tiles.append('a large rounded-square tile with a soft red gradient and a bold white "A" letter inside (Microsoft Access)')
        if tiles:
            tile_block = (
                "Below the title, arrange the following rounded-square app "
                "tiles in a single horizontal row, evenly spaced, with subtle "
                "soft drop-shadows: " + "; ".join(tiles) + ". Each tile is "
                "approximately 1/5 of the card width."
            )
    elif "word" in name_lower:
        tile_block = ('Below the title, a single large rounded-square tile with a soft blue gradient and a bold white "W" letter inside (Microsoft Word), centred.')
    elif "excel" in name_lower:
        tile_block = ('Below the title, a single large rounded-square tile with a soft green gradient and a bold white "X" letter inside (Microsoft Excel), centred.')
    elif "project" in name_lower:
        tile_block = ('Below the title, a single large rounded-square tile with a soft emerald gradient and a bold white "P" letter inside (Microsoft Project), centred.')
    elif "visio" in name_lower:
        tile_block = ('Below the title, a single large rounded-square tile with a soft indigo gradient and a bold white "V" letter inside (Microsoft Visio), centred.')
    elif "windows" in name_lower:
        tile_block = ("Below the title, a single large rendered Microsoft Windows logo (the modern flat four-pane blue logo) centred in the card.")
    elif "bitdefender" in name_lower:
        tile_block = ("Below the title, a single large flat crimson-red shield with a small white wolf inside, centred in the card.")
    elif "mcafee" in name_lower:
        tile_block = ("Below the title, a single large crimson-red rounded shield with a small white check-mark inside, centred in the card.")

    # --- Specs strip (bottom) ---
    if "office" in name_lower:
        if "professional plus" in name_lower or "home & business" in name_lower or "home and business" in name_lower:
            users, devices = "1 User", "PC + Mobile"
        elif "home & student" in name_lower or "home and student" in name_lower or "home 2024" in name_lower or "home 2021" in name_lower or "home 2019" in name_lower:
            users, devices = "1 User", "Single Device"
        else:
            users, devices = "1 User", "Single Device"
        specs_block = (
            f"At the bottom of the card add a slim light-grey horizontal strip showing two small icons: "
            f"first a tiny person-silhouette icon with the label \"{users}\" beneath it, "
            f"then a tiny laptop-with-arrow icon with the label \"{devices}\" beside it."
        )
    elif "windows" in name_lower:
        specs_block = (
            'At the bottom of the card add a slim light-grey horizontal strip showing a tiny laptop icon with the label "1 PC" beside it and a tiny lock icon with the label "Lifetime" beside it.'
        )
    elif "bitdefender" in name_lower or "mcafee" in name_lower or "antivirus" in category:
        # Try to extract devices from name e.g. "3 Mac"
        devices = "1 Device"
        m_dev = None
        import re as _re
        for pattern in (r'(\d+)\s*Mac', r'(\d+)\s*Device', r'(\d+)\s*PC'):
            m_dev = _re.search(pattern, name, _re.IGNORECASE)
            if m_dev:
                devices = m_dev.group(0)
                break
        years = "1 Year"
        m_yr = _re.search(r'(\d+)\s*Year', name, _re.IGNORECASE)
        if m_yr:
            years = m_yr.group(0)
        specs_block = (
            f'At the bottom of the card add a slim light-grey horizontal strip showing a tiny shield icon with the label "{devices}" beside it and a tiny calendar icon with the label "{years}" beside it.'
        )
    else:
        specs_block = "At the bottom of the card add a slim light-grey horizontal strip with a small product-tag icon."

    return (
        "Create a clean, modern, FLAT product-marketing card on a pure white "
        "background (NOT a 3D box, NOT a glossy mock-up — a flat 2D card "
        "exactly like an official software-publisher's product tile on a "
        "retail website).  Add a thin soft drop-shadow around the card edge.  "
        f"{brand_block}  In the centre, in large bold deep-blue "
        f"(#2B6CB0 / Microsoft-blue) text using a clean modern sans-serif "
        f"font, write the product title: \"{title}\".  "
        f"{tile_block}  {specs_block}  "
        "The whole card MUST be square (1:1), perfectly centred, photographic "
        "quality, sharp text rendering, NO neon colours, NO bright yellow "
        "background, NO 3D box, NO hand-drawn elements, NO human figures, "
        "NO additional decorative text other than what is described above. "
        "The card should look like an official Microsoft/Bitdefender/McAfee "
        "retail listing image."
    )


async def generate_one(source_url: str, meta: dict, retries: int = 2) -> str | None:
    """Generate ONE image, save BOTH the raw PNG and a compressed WebP
    (the WebP is what the storefront actually serves — keeps page loads
    fast).  Returns the internal URL of the WebP."""
    slug = meta["slug"]
    out_png  = OUT_DIR / f"{slug}.png"
    out_webp = OUT_DIR / f"{slug}.webp"
    if out_webp.exists() and out_webp.stat().st_size > 5000:
        # Idempotent — skip already-generated files.
        return f"{INTERNAL_PREFIX}/{slug}.webp"

    prompt = build_prompt(meta)
    for attempt in range(retries + 1):
        try:
            chat = (
                LlmChat(
                    api_key=API_KEY,
                    session_id=f"product-img-{slug}-{int(time.time())}",
                    system_message="You generate clean retail product card images.",
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
            out_png.write_bytes(data)
            # ----------------------------------------------------------------
            # Resize the AI-generated PNG and re-encode as WebP using Pillow.
            # Previously this shelled out to `convert` (ImageMagick) and
            # `cwebp`, but neither binary is guaranteed to be installed in
            # the Emergent container.  Pillow ships with the venv and
            # produces a smaller, sharper result in pure Python.
            # ----------------------------------------------------------------
            try:
                from PIL import Image as _PILImage
            except ImportError as _imp_e:
                print(f"  [{slug}] Pillow not installed: {_imp_e}")
                return None
            with _PILImage.open(out_png) as im:
                im = im.convert("RGBA") if im.mode in ("P", "LA", "RGBA") else im.convert("RGB")
                # Match the original `convert -resize 720x720>` semantics:
                # only downscale (never enlarge), preserve aspect ratio.
                im.thumbnail((720, 720), _PILImage.LANCZOS)
                # WebP, quality 82, drop metadata for fast serving.
                im.save(out_webp, format="WEBP", quality=82, method=6)
            return f"{INTERNAL_PREFIX}/{slug}.webp"
        except Exception as e:
            print(f"  [{slug}] error attempt {attempt+1}: {e}")
            await asyncio.sleep(1.5)
    return None


async def main():
    # CLI mode: --slug <slug> (+ optional --name/--brand/--category/--platform/--apps)
    # → regenerate exactly one product.  PHP passes the metadata directly
    # via CLI args so the Python script never needs DB credentials.
    import argparse
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--slug", help="Regenerate only this single product slug")
    parser.add_argument("--name", default="")
    parser.add_argument("--brand", default="")
    parser.add_argument("--category", default="")
    parser.add_argument("--platform", default="")
    parser.add_argument("--apps", default="")
    args = parser.parse_args()

    if args.slug:
        row = {
            "slug":     args.slug,
            "name":     args.name or args.slug.replace("-", " ").title(),
            "brand":    args.brand,
            "category": args.category,
            "platform": args.platform,
            "apps":     args.apps,
        }
        # Wipe any existing artefacts so the generator definitely overwrites.
        for ext in (".png", ".webp", ".jpg"):
            (OUT_DIR / f"{args.slug}{ext}").unlink(missing_ok=True)

        internal = await generate_one(args.slug, row)
        if internal:
            print(internal)   # stdout = the new path so PHP can capture it
            sys.exit(0)
        sys.exit(3)

    # Bulk mode (default): read /tmp/img_remap.json.
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
