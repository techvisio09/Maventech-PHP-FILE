#!/usr/bin/env python3
"""
Rewrite /app/php-version/database.sql so that every gosoftwarebuy.com image
URL is replaced with its locally-generated /uploads/products/<slug>.png
counterpart.  Idempotent — safe to run multiple times.
"""
import json
from pathlib import Path

MAP   = json.loads(Path("/tmp/img_remap_done.json").read_text())
SQL   = Path("/app/php-version/database.sql")

text  = SQL.read_text()
before = text.count("gosoftwarebuy.com")

for src, dst in MAP.items():
    text = text.replace(src, dst)

after = text.count("gosoftwarebuy.com")
SQL.write_text(text)
print(f"database.sql: gosoftwarebuy.com refs before={before}, after={after}")
