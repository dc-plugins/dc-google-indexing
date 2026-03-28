=== DC Google Indexing ===
Contributors: lennilg
Tags: google, indexing, seo, search console, instant indexing
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.9
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Submit URLs to Google's Web Search Indexing API for instant crawling — no waiting for Googlebot.

== Description ==

DC Google Indexing connects your WordPress site to the **Google Web Search Indexing API**, allowing you to notify Google the moment new content is published or existing content is updated. Instead of waiting days for Googlebot to discover your changes, Google is notified immediately.

**How it works**

The plugin uses a Google Cloud Service Account and OAuth2 to authenticate requests. URLs are queued and processed by WP-Cron every 5 minutes, respecting Google's 200 URL/day quota.

**Features**

* 🚀 Instant URL submission via Google Web Search Indexing API
* 🔄 Auto-submit on publish/update — configurable per post type
* 📋 Manual batch submission — paste a list of URLs
* 📊 Live queue viewer with "Process Now" button
* 📝 Submission log with success/error status per URL
* 📈 Daily quota tracker (200/day default)
* 🔐 Property-aware onboarding with Search Console property selection and connection diagnostics
* 🧪 Test connection — validates credentials without sending any URL
* 🔑 No external libraries — pure PHP JWT/OAuth2 implementation
* ✅ Getting Started guide with step-by-step setup instructions
* 🗂️ Index Status dashboard — background URL inspection with verdict & coverage breakdown
* 🔎 URL inspection detail with cached inspection data and live Indexing API metadata
* ♻️ One-click re-submit for URLs that are still not indexed
* ⚡ Cache-based polling — zero Inspection API quota consumed during queue polling
* 🌍 Translation ready

**Requirements**

* Google Cloud project with Web Search Indexing API enabled
* Service Account with JSON key
* Site verified in Google Search Console with the service account added as an Owner
* PHP `openssl` extension (standard on all hosts)

== Installation ==

1. Upload the plugin to `/wp-content/plugins/dc-google-indexing/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Google Indexing** in the admin menu
4. Follow the **Getting Started** guide to connect your Google Cloud account
5. Start submitting URLs!

== Frequently Asked Questions ==

= Does this work for all websites? =

Yes — any site verified in Google Search Console can use the Indexing API. You need a Google Cloud project and a service account.

= Is this the same as IndexNow? =

No. IndexNow is a protocol supported by Bing, Yandex, and others. This plugin uses Google's own **Web Search Indexing API**, which communicates directly with Googlebot.

= What is the daily limit? =

Google allows 200 URL submissions per day by default. You can request a quota increase in Google Cloud Console if needed. The plugin tracks your daily usage automatically.

= Do I need billing enabled on Google Cloud? =

No. The Web Search Indexing API is free within the default quota. No billing is required.

= What happens if the queue exceeds 200 URLs? =

Extra URLs remain in the queue and are processed the following day when the quota resets.

= Is my JSON key stored securely? =

The service account JSON is stored in your WordPress database (wp_options). It is not exposed in the front-end. Treat it like any other sensitive credential.

== Screenshots ==

1. Getting Started guide — step-by-step setup with direct links to Google Cloud Console
2. Settings — service account credentials, auto-submit, post type selection
3. Submit URLs — paste a list of URLs for instant submission
4. Queue — view and process pending URLs
5. Log — submission history with status per URL
6. Index Status — verdict distribution donut chart and colour-coded coverage-state breakdown

== Third Party Services ==

This plugin communicates with the following external services operated by Google LLC. Data is only transmitted when a user has configured a Google Cloud service account and actively uses the plugin.

= Google Web Search Indexing API =

* Service URL: https://indexing.googleapis.com/
* Purpose: Submits page URLs to Google so they can be crawled and indexed immediately.
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://developers.google.com/search/apis/indexing-api/v3/terms

= Google Search Console API (URL Inspection & Search Analytics) =

* Service URLs: https://searchconsole.googleapis.com/ and https://www.googleapis.com/webmasters/v3/
* Purpose: Inspects the indexing status of individual URLs, retrieves the list of verified Search Console properties, and fetches search-performance analytics (clicks, impressions, CTR, position).
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://developers.google.com/terms/

= Google OAuth2 Token Endpoint =

* Service URL: https://oauth2.googleapis.com/token
* Purpose: Exchanges a signed JWT (built from the user-supplied service account key) for a short-lived bearer token used to authenticate all API requests. No user credentials beyond the service account key are transmitted.
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://policies.google.com/terms

= Google Service Usage API =

* Service URL: https://serviceusage.googleapis.com/
* Purpose: Retrieves real API quota limits for the Indexing API to display accurate quota information in the admin dashboard.
* Privacy Policy: https://policies.google.com/privacy
* Terms of Service: https://developers.google.com/terms/

No personal user data is sent to any of these services. Only the URLs of pages on the user's own WordPress site and the service account credentials supplied by the site administrator are transmitted.

== Changelog ==

= 1.3.9 =
* Fix: Added third-party service disclosures to the plugin readme in compliance with WordPress.org guidelines.

= 1.3.0 =
* New: Search Analytics tab — fetches clicks, impressions, CTR, and average position from Google Search Console and overlays the data on the Index Status table.
* New: Quality Assurance scanner — crawls flagged URLs for common on-page SEO issues (missing titles, canonical mismatches, noindex tags, redirect chains) directly from the admin dashboard.
* New: Real-time quota metrics panel powered by the Google Service Usage API, showing the live daily quota limit alongside the plugin's own usage counter.
* Improvement: Analytics date-range selector (7 / 28 / 90 days) added to the Index Status view.

= 1.2.0 =
* New: Explicit Search Console property setting with support for URL-prefix and `sc-domain:` properties.
* New: Connection diagnostics that verify Indexing API auth, Search Console auth, and selected-property access.
* New: Index Status inspect view with per-URL metadata from both the URL Inspection API cache and the Indexing API metadata endpoint.
* New: Re-submit actions directly from the Index Status table for URLs that are still excluded.
* Improvement: Removed the optional footer-credit feature to better align with hosted marketplace expectations.

= 1.1.0 =
* New: Index Status dashboard tab with verdict distribution (donut chart) and colour-coded coverage-state breakdown.
* New: Background URL inspection cron — inspects 3 URLs/min via the URL Inspection API and stores results in a dedicated DB table.
* New: Cache-based polling — queue polling now reads verdicts from the local cache instead of calling the Inspection API directly, consuming zero quota per poll run.
* New: Clear Cache button and cache stats panel in the Polling tab.
* Improvement: Complete docblocks added to all functions across all plugin files.
* Fix: Various PHPCS/WordPress Coding Standards issues resolved throughout the codebase.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.9 =
Adds third-party service disclosures required by WordPress.org guidelines. No code-level changes.

= 1.3.0 =
Adds Search Analytics overlay, a Quality Assurance scanner, and a real-time quota metrics panel.

= 1.2.0 =
Adds Search Console property-aware setup, a richer connection test, and per-URL inspect/re-submit actions in the Index Status dashboard.

= 1.1.0 =
Adds a new Index Status tab with background URL inspection and a cache-based polling system. A new database table (`{prefix}dc_gi_url_cache`) is created automatically on upgrade.

= 1.0.0 =
Initial release.
