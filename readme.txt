=== Progressio Performance Tracker ===
Contributors: progressiodev
Tags: ga4, google analytics, conversion tracking, event tracking, button clicks
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track GA4 custom events for CTA clicks, form submissions, scroll depth, phone/email clicks, and traffic attribution — no GA4 configuration required.

== Description ==

Progressio Performance Tracker fires custom GA4 events whenever a visitor interacts with key elements on your site. All conversion events include traffic attribution data (UTM source, medium, campaign, keyword) so you can connect where a visitor came from to what action they took — the foundation of meaningful client reporting.

= What It Tracks =
* **CTA Button Clicks** — Three configurable tiers (Primary, Secondary, Tertiary) by CSS class
* **Form Submissions** — WS Form, Gravity Forms, WPForms, Contact Form 7, Fluent Forms, Formidable Forms, Ninja Forms, Generic fallback
* **Scroll Depth** — 25%, 50%, 75%, 100% milestones
* **Outbound Link Clicks**
* **Phone Number Clicks** (tel: links)
* **Email Clicks** (mailto: links)

= Attribution =
UTM parameters (utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, fbclid) are captured on landing and persisted in sessionStorage. Every conversion event carries this attribution so GA4 reports can show keyword → button click or campaign → form submission in a single view.

= GA4 Configuration Required =
None. Events appear in GA4 automatically. Optionally mark cta_click or form_submit as conversions with one toggle inside GA4.

== Installation ==

1. Upload the `progressio-performance-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Go to **Perf Tracker** in the admin sidebar and enter your GA4 Measurement ID.
4. Configure your CTA classes and form plugin.
5. Save Settings. Done.

== Frequently Asked Questions ==

= Do I need to configure anything in GA4? =
No. Events appear automatically in GA4 Realtime → Events and in Reports → Events within 24 hours. The only optional GA4 step is marking events as conversions.

= I use Google Site Kit. Do I need to enter my Measurement ID? =
No — Site Kit already loads gtag.js. Uncheck "Load gtag.js" in the plugin settings to avoid a duplicate tag, and leave the Measurement ID blank.

= What if my form plugin isn't listed? =
Select "Generic (HTML form submit fallback)". It listens for the native form submit event and works with any plugin that performs a standard HTML form submission.

== Changelog ==

= 1.0.0 =
* Initial release.
