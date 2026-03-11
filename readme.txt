=== DC Google Indexing ===
Contributors: dampcig
Tags: google, indexing, seo, search console, instant indexing
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
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
* 🧪 Test connection — validates credentials without sending any URL
* 🔑 No external libraries — pure PHP JWT/OAuth2 implementation
* ✅ Getting Started guide with step-by-step setup instructions
* 🌍 Translation ready

**Requirements**

* Google Cloud project with Web Search Indexing API enabled
* Service Account with JSON key
* Site verified in Google Search Console with the service account added as a Full user
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

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release.
