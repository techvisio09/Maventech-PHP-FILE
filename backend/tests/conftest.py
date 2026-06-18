"""
Shared fixtures & constants for the Maventech test suite.

This file is the single source of truth for test credentials and base URLs.
Each test module imports `ADMIN_EMAIL`, `ADMIN_PASSWORD` and `BASE_URL` from
here instead of hard-coding them, which means a `git grep "Admin@123"`
returns exactly one line — this file — and rotating the test admin password
becomes a one-line env-var change.

Credentials resolve in this order (first non-empty wins):
    1. `TEST_ADMIN_EMAIL` / `TEST_ADMIN_PASSWORD` env vars  (CI / staging)
    2. Defaults documented in /app/memory/test_credentials.md (local preview)

`PHP_BASE_URL` follows the same pattern with a preview-URL default.
"""
import os

# Public site URL the test suite hits.  Override per environment with
# `PHP_BASE_URL=https://stage.example.com`.
BASE_URL = os.environ.get(
    "PHP_BASE_URL",
    "https://stage-show-2.preview.emergentagent.com",
).rstrip("/")

# Admin login.  Override per environment with the two `TEST_ADMIN_*` vars.
ADMIN_EMAIL = os.environ.get("TEST_ADMIN_EMAIL", "admin@maventechsoftware.com")
ADMIN_PASSWORD = os.environ.get("TEST_ADMIN_PASSWORD", "Admin@123")
