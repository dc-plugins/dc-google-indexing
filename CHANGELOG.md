# Changelog

All notable changes to DC Google Indexing are documented here.

## [1.4.0] – 2026-04-17

### Added
- Background URL Inspection API signal on watchlist re-submit — the Re-submit button now also fires a URL Inspection API call via WP-Cron (`dc_gi_inspection_signal` hook), giving Google a second, non-blocking crawl hint without touching the Indexing API quota.

### Improved
- Extracted five shared helpers (`dc_gi_get_validated_sa`, `dc_gi_update_option`, `dc_gi_push_log_entry`, `dc_gi_cleanup_watch_check`, `dc_gi_resolve_watchlist_status`) to eliminate duplicated credential validation, option-write, log-append, and watchlist status-transition logic across all cron callbacks and AJAX handlers.
- `DC_GI_RESUBMIT_STATES` and `DC_GI_QA_STATES` constants replace inline array literals so coverage-state lists are defined once and referenced everywhere.

## [1.3.9] – 2025-05-01

### Fixed
- Added third-party service disclosures to the plugin readme in compliance with WordPress.org guidelines. No code-level changes.

## [1.3.0] – 2025-04-01

### Added
- Search Analytics tab — fetches clicks, impressions, CTR, and average position from Google Search Console and overlays the data on the Index Status table.
- Quality Assurance scanner — crawls flagged URLs for common on-page SEO issues (missing titles, canonical mismatches, noindex tags, redirect chains) directly from the admin dashboard.
- Real-time quota metrics panel powered by the Google Service Usage API, showing the live daily quota limit alongside the plugin's own usage counter.

### Improved
- Analytics date-range selector (7 / 28 / 90 days) added to the Index Status view.

## [1.2.0]

### Added
- Explicit Search Console property setting with support for URL-prefix and `sc-domain:` properties.
- Connection diagnostics that verify Indexing API auth, Search Console auth, and selected-property access.
- Index Status inspect view with per-URL metadata from both the URL Inspection API cache and the Indexing API metadata endpoint.
- Re-submit actions directly from the Index Status table for URLs that are still excluded.

### Improved
- Removed the optional footer-credit feature to better align with hosted marketplace expectations.

## [1.1.0]

### Added
- Index Status dashboard tab with verdict distribution (donut chart) and colour-coded coverage-state breakdown.
- Background URL inspection cron — inspects 3 URLs/min via the URL Inspection API and stores results in a dedicated DB table (`{prefix}dc_gi_url_cache`).
- Cache-based polling — queue polling now reads verdicts from the local cache instead of calling the Inspection API directly, consuming zero quota per poll run.
- Clear Cache button and cache stats panel in the Polling tab.

### Improved
- Complete docblocks added to all functions across all plugin files.

### Fixed
- Various PHPCS/WordPress Coding Standards issues resolved throughout the codebase.

## [1.0.0]

### Added
- Initial release.
