# DC Google Indexing

> Submit URLs to Google's Web Search Indexing API for instant crawling — no waiting for Googlebot.

![Version](https://img.shields.io/badge/version-1.2.0-blue)
![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-green)

## Overview

**DC Google Indexing** connects your WordPress site directly to the [Google Web Search Indexing API](https://developers.google.com/search/apis/indexing-api/v3/quickstart), notifying Google the instant new content is published or existing content is updated.

Authentication uses a Google Cloud Service Account with OAuth2 — implemented in pure PHP with no external libraries.

## Features

- 🚀 **Instant URL submission** via Google Web Search Indexing API
- 🔄 **Auto-submit** on publish/update — configurable per post type
- 📋 **Manual batch submission** — paste a list of URLs
- 📊 **Live queue viewer** with "Process Now" button
- 📝 **Submission log** with success/error status per URL
- 📈 **Daily quota tracker** (200/day default)
- 🔐 **Property-aware onboarding** with Search Console property selection and connection diagnostics
- 🗂️ **Index Status dashboard** — background URL inspection with verdict & colour-coded coverage-state breakdown
- 🔎 **URL inspection detail** with cached inspection data and live Indexing API notification metadata
- ♻️ **One-click re-submit** for URLs that are still not indexed
- ⚡ **Cache-based polling** — zero Inspection API quota consumed during queue polling
- 🧪 **Test connection** — validates credentials without submitting any URL
- 🔑 **No external libraries** — pure PHP JWT/OAuth2 implementation
- ✅ **Getting Started guide** with step-by-step setup instructions
- 🌍 **Translation ready**

## Requirements

- WordPress 6.8+
- PHP 7.4+ with `openssl` extension
- Google Cloud project with [Web Search Indexing API](https://console.cloud.google.com/apis/library/indexing.googleapis.com) enabled
- Service Account with a JSON key
- Site verified in [Google Search Console](https://search.google.com/search-console) with the service account added as an **Owner**

## Installation

1. Download the latest `dc-google-indexing.zip` from [Releases](https://github.com/dc-plugins/dc-google-indexing/releases).
2. In WordPress admin go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and activate.
4. Go to **Google Indexing** in the admin sidebar.
5. Follow the **Getting Started** guide to connect your Google Cloud account.

## Repository Layout

```
dc-google-indexing/
├── .github/
│   ├── copilot-instructions.md
│   └── workflows/deploy.yml       ← SSH rsync deploy + ZIP artifact + GitHub Release
├── languages/                     ← translation placeholder
├── dc-google-indexing.php         ← main plugin file (cron, hooks, queue, watchlist)
├── admin.php                      ← admin dashboard, form handlers, AJAX endpoints
├── class-jwt.php                  ← Google OAuth2/JWT + Indexing/Inspection API client
├── class-sitemap.php              ← XML sitemap discovery and parsing
├── class-url-cache.php            ← DB-backed URL inspection cache
├── uninstall.php                  ← cleanup on plugin deletion
├── composer.json                  ← dev: php_codesniffer + wpcs
└── phpcs.xml                      ← PHPCS config (WordPress-Core/Docs/Extra)
```

## Development

```bash
# Install dev dependencies
composer install

# Run PHP CodeSniffer (WordPress Coding Standards)
vendor/bin/phpcs
```

There is no test suite. PHPCS is the only validation step — run it before opening a pull request.

## Changelog

### 1.2.0
- **New:** Explicit Search Console property setting with support for URL-prefix and `sc-domain:` properties.
- **New:** Connection diagnostics that verify Indexing API auth, Search Console auth, and selected-property access.
- **New:** Index Status inspect view with per-URL metadata from both the URL Inspection API cache and the Indexing API metadata endpoint.
- **New:** Re-submit actions directly from the Index Status table for URLs that are still excluded.
- **Improvement:** Removed the optional footer-credit feature to better align with hosted marketplace expectations.

### 1.1.0
- **New:** Index Status dashboard tab with verdict distribution (donut chart) and colour-coded coverage-state breakdown.
- **New:** Background URL inspection cron — inspects 3 URLs/min via the URL Inspection API and stores results in a dedicated DB table (`{prefix}dc_gi_url_cache`).
- **New:** Cache-based polling — queue polling reads verdicts from the local cache, consuming zero Inspection API quota per poll run.
- **New:** Clear Cache button and cache stats panel in the Polling tab.
- **Improvement:** Complete docblocks added to all functions across all plugin files.
- **Fix:** Various PHPCS/WordPress Coding Standards issues resolved throughout the codebase.

### 1.0.0
- Initial release.

## License

[GPL-2.0+](https://www.gnu.org/licenses/gpl-2.0.html)
