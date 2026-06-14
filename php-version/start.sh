#!/bin/bash
# ============================================================
# Emergent preview launcher — serves the PHP store on port 3000
# (replaces the React dev server; supervisor runs this via `yarn start`)
# Self-healing: starts MariaDB if needed and seeds the database
# on a fresh pod. NOT needed on normal PHP hosting (cPanel etc.)
# ============================================================
set -e

# Secrets ARE NOT HARDCODED HERE.  They are loaded from /app/backend/.env
# (which is git-ignored).  In production, set the same env vars in your
# hosting control panel.  See section "Export integration keys" below.

# 1) Ensure MariaDB is running
if ! mysqladmin ping --silent 2>/dev/null; then
  mkdir -p /run/mysqld
  chown mysql:mysql /run/mysqld 2>/dev/null || true
  (mysqld_safe --skip-grant-tables=0 >/dev/null 2>&1 &)
  for i in $(seq 1 30); do
    mysqladmin ping --silent 2>/dev/null && break
    sleep 1
  done
fi

# 2) Seed the database if missing (fresh pod)
if ! mysql -uroot -e "USE ucode_store" 2>/dev/null; then
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS ucode_store CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  mysql -uroot ucode_store < /app/php-version/database.sql
  echo "[start.sh] Database ucode_store created and seeded"
fi

# 2b) Idempotent schema migrations (safe on every boot)
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS activation_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true
mysql -uroot ucode_store -e "ALTER TABLE products ADD COLUMN IF NOT EXISTS install_guide_url VARCHAR(500) DEFAULT NULL" 2>/dev/null || true

# Visitor analytics — one row per public page view from a real human (bots/admin skipped at the PHP layer).
mysql -uroot ucode_store -e "CREATE TABLE IF NOT EXISTS visitor_log (
    id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    session_id VARCHAR(64) NOT NULL DEFAULT '',
    ip_hash VARCHAR(64) NOT NULL DEFAULT '',
    user_agent VARCHAR(500) NOT NULL DEFAULT '',
    os VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    browser VARCHAR(40) NOT NULL DEFAULT 'Unknown',
    device VARCHAR(20) NOT NULL DEFAULT 'Desktop',
    country VARCHAR(8) NOT NULL DEFAULT '',
    page_url VARCHAR(255) NOT NULL DEFAULT '',
    referer VARCHAR(255) NOT NULL DEFAULT '',
    visited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_visited (visited_at),
    KEY idx_session (session_id),
    KEY idx_os (os),
    KEY idx_device (device)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" 2>/dev/null || true

# 3) Export integration keys from .env files (preview convenience)
# Load /app/php-version/.env first (PHP-store-specific secrets like Emergent
# LLM key, Stripe, Resend), then /app/backend/.env (only kept for legacy
# MongoDB defaults — protected variables MUST NOT be removed).
for ENVF in /app/php-version/.env /app/backend/.env; do
  if [ -f "$ENVF" ]; then
    while IFS='=' read -r K V; do
      # Skip comments + empty lines
      [ -z "$K" ] && continue
      case "$K" in \#*) continue;; esac
      # Strip surrounding quotes
      V=$(echo "$V" | sed 's/^"//; s/"$//')
      export "$K=$V"
    done < "$ENVF"
  fi
done

# Tighten permissions on /app/php-version/.env so only the running user can read it.
chmod 600 /app/php-version/.env 2>/dev/null || true

# 4) Serve the PHP store on port 3000
exec env PHP_CLI_SERVER_WORKERS=8 php -S 0.0.0.0:3000 -t /app/php-version /app/php-version/router.php
