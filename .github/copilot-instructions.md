# Copilot Instructions for DC Google Indexing

## Overview

**DC Google Indexing** is a WordPress plugin (~4,135 PHP lines) that integrates with Google's Web Search Indexing API to submit URLs for instant crawling. It uses OAuth2 service-account authentication (pure PHP — no external libraries), a WP-Cron-based queue system, and a multi-tab admin dashboard.

- **PHP requirement**: 7.4+  
- **WordPress requirement**: 6.8+  
- **License**: GPL-2.0+

## Repository Layout

```
dc-google-indexing/
├── .github/
│   ├── copilot-instructions.md   ← this file
│   └── workflows/deploy.yml      ← SSH rsync deploy + ZIP artifact + GitHub release
├── languages/                    ← translation placeholder
├── dc-google-indexing.php        ← main plugin file (cron, hooks, queue, watchlist)
├── admin.php                     ← admin dashboard, form handlers, AJAX endpoints
├── class-jwt.php                 ← Google OAuth2/JWT + Indexing/Inspection API client
├── class-sitemap.php             ← XML sitemap discovery and parsing
├── uninstall.php                 ← cleanup on plugin deletion
├── composer.json                 ← dev: php_codesniffer + wpcs
├── phpcs.xml                     ← PHPCS config (WordPress-Core/Docs/Extra + dc_gi prefix)
└── readme.txt                    ← plugin description and FAQ
```

## Linting

There is **no test suite**. The only validation tool is PHP CodeSniffer with WordPress Coding Standards.

```bash
# Install dev dependencies (required first)
composer install

# Run the linter
vendor/bin/phpcs
```

PHPCS is configured by `phpcs.xml`. It checks all `.php` files except `vendor/` and `node_modules/`. **Always run `composer install` before `vendor/bin/phpcs`.**

## Coding Conventions

Follow **WordPress Coding Standards** (PHPCS enforces this). Key rules:

- **Prefix everything**: all functions `dc_gi_*`, all classes `DC_GI_*`, all constants `DC_GI_*`, all option/transient keys `dc_gi_*`.
- **Text domain**: always `dc-google-indexing` for i18n strings.
- **Sanitization**: use `sanitize_*()` + `wp_unslash()` on input; `esc_html()` / `esc_attr()` / `esc_url()` on output.
- **Capability check**: `current_user_can( 'manage_options' )` before any admin action.
- **Nonces**: `wp_create_nonce()` / `check_admin_referer()` for every form and AJAX handler.
- **Options autoload**: always save `dc_gi_watchlist` with `update_option( 'dc_gi_watchlist', $value, false )` (autoload = false).
- **Array syntax**: use short `[]`, not `array()`.
- **Docblocks**: required for every function and method.

## Architecture & Key Patterns

### Data Flow
1. A post is published → `transition_post_status` → `dc_gi_enqueue_url()` (adds to `dc_gi_queue` option).
2. WP-Cron fires `dc_gi_process_queue` every 5 min → `DC_GI_JWT::submit_batch()` sends up to 100 URLs in one multipart/mixed request.
3. Submitted URLs move to `dc_gi_watchlist`; the watchlist cron (every 6 h) calls the URL Inspection API to track coverage state.

### Important Options and Transients
| Key | Type | Purpose |
|-----|------|---------|
| `dc_gi_settings` | option | Plugin settings (service account JSON, auto_submit, auto_delete, post_types, daily_quota, footer_credit) |
| `dc_gi_queue` | option | Pending URL array |
| `dc_gi_log` | option | Last 100 submission records |
| `dc_gi_watchlist` | option | Up to 500 tracked URLs (autoload=false) |
| `dc_gi_quota_YYYY-MM-DD` | transient | Daily submission count (24 h TTL) |
| `dc_gi_access_token` | transient | Cached OAuth2 token for Indexing API (~1 h) |
| `dc_gi_inspection_token` | transient | Cached OAuth2 token for URL Inspection API (~1 h) |
| `dc_gi_poll_lock` | transient | 30-second lock preventing concurrent cron + AJAX polling |
| `dc_gi_sitemap_urls_cache` | transient | Cached sitemap URLs (5-min TTL) |

### Quota
`dc_gi_is_quota_exhausted()` returns `true` when `dc_gi_get_quota_used() >= daily_quota`. Polling stops early with `'early:quota_exhausted'`; watchlist checks skip re-submissions but still inspect.

### auto_delete Behaviour
The `auto_delete` setting (inside `dc_gi_settings`) controls `URL_DELETED` notifications for trash/unpublish/password hooks. Defaults to `true` when absent (backward compat). Independent from `auto_submit`.

### Trash Hook
Use `wp_trash_post` action (not `transition_post_status`) to detect trashing — it fires before the `__trashed` slug suffix is added, so `get_permalink()` returns the correct public URL.

### Logging
Use `dc_gi_log_info()` for informational-only entries (e.g. `SITEMAP_REMOVED`, `POLL_404`) where no watchlist side effects are wanted.

### Classes
- **`DC_GI_JWT`** (`class-jwt.php`): static methods for OAuth2 token exchange, single and batch URL submission, URL inspection, and connection testing. Pure PHP RS256 JWT using `openssl_sign()`.
- **`DC_GI_Sitemap`** (`class-sitemap.php`): static `get_urls( $limit )` that discovers sitemaps from robots.txt or common WordPress paths, recursively parses sitemap indexes, and returns a flat URL array.
