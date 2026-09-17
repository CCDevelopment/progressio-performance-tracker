=== Progressio Performance Tracker ===
Contributors: progressiodev
Tags: ga4, google analytics, conversion tracking, event tracking, button clicks
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track GA4 custom events for CTA clicks, form submissions, scroll depth, downloads, video, phone/email clicks, and first/last-touch traffic attribution — no GA4 configuration required.

== Description ==

Progressio Performance Tracker fires custom GA4 events whenever a visitor interacts with key elements on your site. Every event carries first-touch and last-touch attribution (source, medium, campaign, keyword, channel, click IDs) so you can connect where a visitor came from to what action they took — the foundation of meaningful client reporting.

= What It Tracks =
* **CTA Button Clicks** — Three configurable tiers by CSS class, or `data-ppt-cta="primary"` / `data-ppt-label="…"` attributes on any element
* **Form Submissions** — WS Form, Gravity Forms, WPForms, Contact Form 7, Fluent Forms, Formidable Forms, Ninja Forms, Elementor Pro Forms, Generic fallback
* **Leads** — `generate_lead` fires once the Progressio Leads API accepts a submission
* **Scroll Depth** — 25%, 50%, 75%, 100% milestones
* **Outbound Link Clicks**, **File Downloads** (PDF, docs, spreadsheets, archives, media)
* **Video Engagement** — start / 25 / 50 / 75 / complete for YouTube, Vimeo and HTML5 video
* **Phone Number Clicks** (tel: links) and **Email Clicks** (mailto: links)

= Attribution =
The first touch (how the visitor originally found the site — UTM parameters, gclid/fbclid/msclkid/ttclid/li_fat_id/dclid, or referrer) is stored in a first-party cookie for a configurable window (default 90 days). The last touch is stored per session. Both are classified into a marketing channel (Paid Search, Paid Social, Organic Search, Organic Social, Email, Referral, Direct, …) mirroring GA4's default channel grouping, and both are sent with every event and every lead.

= Lead Capture =
With a Leads API key set, form submissions are captured server-side (immune to ad-blockers) and sent to the Progressio dashboard with name, email, phone, message, full attribution, a stable visitor ID and a contact hash for deduplication. Delivery is confirmed; failures are queued and retried automatically with backoff. An optional per-form field mapping pins exactly which fields hold the contact details.

= Configuration via wp-config.php =
`PPT_LEADS_API_KEY`, `PPT_LEADS_ENDPOINT` and `PPT_MEASUREMENT_ID` constants override the stored settings, so secrets never need to live in the database. The `ppt_settings` filter does the same from an mu-plugin.

= WP-CLI =
`wp ppt status`, `wp ppt test-lead`, `wp ppt queue [--process|--clear]`, `wp ppt get <key>`, `wp ppt set <key> <value>`.

== Installation ==

1. Upload the `progressio-performance-tracker` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins** in WordPress admin.
3. Go to **Perf Tracker** in the admin sidebar and enter your GA4 Measurement ID.
4. Configure your CTA classes and form plugin.
5. Save Settings. The Status panel at the top of the page confirms what has been detected.

== Frequently Asked Questions ==

= Do I need to configure anything in GA4? =
No. Events appear automatically in GA4 Realtime → Events and in Reports → Events within 24 hours. To report on the attribution parameters, register them as custom dimensions with `scripts/ga4-setup/create-custom-dimensions.js`, or mark `cta_click` / `form_submit` / `generate_lead` as key events inside GA4.

= I use Google Site Kit. Do I need to enter my Measurement ID? =
No — Site Kit already loads gtag.js. Uncheck "Load gtag.js" in the plugin settings to avoid a duplicate tag, and leave the Measurement ID blank. The Status panel warns you if both are loading.

= What if my form plugin isn't listed? =
Select "Generic (HTML form submit fallback)". It listens for the native form submit event and works with any plugin that performs a standard HTML form submission. Lead capture requires one of the supported plugins.

= The wrong field is being sent as the lead's name / phone =
Add a row under **Lead Field Mapping** for that form. Keys match the field ID or label; join several with `|` (e.g. `First Name|Last Name`).

== Changelog ==

= 1.6.0 =
**SEO meta over the REST API**
* SEO title, meta description and focus keyword can now be set over the WordPress REST API (`POST`/`PUT` to `/wp/v2/posts`, `meta` object) on sites running Rank Math. Rank Math stores these as ordinary post meta and does not register the keys for REST writes, so WordPress discarded them silently — the post saved with a `201` and the SEO fields stayed empty, with nothing in the response to indicate it. This lets the Progressio Dash content pipeline publish posts with their SEO fields already filled in.
* Supported plugins and keys — only the keys of the plugin actually active are considered, so no orphan meta is created:
  * **Rank Math** — `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`
  * **Yoast SEO** — `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`
* Current Yoast releases (verified on Yoast SEO 28.5) already register these three keys for REST themselves, so they were already writable there. Any key an SEO plugin already exposes is left untouched rather than re-registered — re-registering would silently replace that plugin's own sanitize and auth callbacks with ours. Older Yoast builds that lack the registration are still covered.
* Writes are gated on the `edit_post` capability for the specific post, so an authenticated low-privilege user (a Subscriber, for example) cannot rewrite SEO meta. Registration covers the `post` post type; widen it with the `ppt_seo_meta_post_types` filter.
* Only single-line string fields are exposed. Robots directives, schema and social overrides are array or serialized values and are deliberately left alone.
* Sites running neither Rank Math nor Yoast register nothing and are unaffected; deactivating the SEO plugin is safe.
* Lead payloads now carry a read-only `seoPlugin` field (`rank_math`, `yoast`, `rank_math+yoast` or `none`) so the dashboard can report whether SEO meta is writable without having to attempt a publish first.

= 1.5.0 =
**Security**
* Attribution cookies are now sanitized, type-checked and length-capped before use; the marketing channel is recomputed server-side rather than trusted from the client.
* Lead contact fields are sanitized and validated (`is_email`, phone digit check, length caps) before sending.
* The Leads endpoint must be HTTPS on an approved host; requests go through `wp_safe_remote_post()`. Staging hosts can be added with the `ppt_allowed_lead_hosts` filter or the `PPT_LEADS_ENDPOINT` constant.
* The API key is no longer rendered into the settings page; it is also sent as an `Authorization: Bearer` header.
* Stored page URLs keep only the path and marketing parameters — never full query strings.
* Attribution cookies carry the `Secure` flag on HTTPS.
* Measurement ID is validated against the `G-XXXXXXXXXX` format.
* Added `uninstall.php` (removes all options, queue and cron) and directory `index.php` guards.
* Settings sanitizer no longer fatals on non-array input.

**Attribution**
* First-touch attribution in a first-party cookie (configurable window, default 90 days) alongside per-session last touch. Both are sent on every GA4 event (`first_*` / `traffic_*`) and every lead.
* Channel classification (`traffic_channel`, `first_channel`) mirroring GA4's default channel grouping.
* Google Ads auto-tagged clicks (`gclid` with no UTMs) are now attributed; added `msclkid`, `ttclid`, `li_fat_id`, `dclid`.
* Fixed `www.` sites reporting their own internal navigation as a referral source.
* `landingPage` in lead payloads is now the true first landing page; `sessionLandingPage` and `pageUrl` are separate.

**Lead capture**
* Optional per-form field mapping (name / email / phone / message) with improved label heuristics as the fallback; `message` is now captured.
* Payload adds `channel`, `firstTouch`, `lastTouch`, `visitorId`, `submissionId`, `contactHash`, `ipHash`, `userAgent`, `isSpam`/`spamScore` where the form plugin reports it, and `formPlugin`.
* Delivery is now confirmed. Failures are queued and retried by WP-Cron with backoff (5 attempts); the Status panel shows last sent / last error / queue size and offers "Send test lead" and "Retry queued leads now".
* GA4 `generate_lead` event fires once the Leads API accepts a lead, with `lead_id` when the API returns one.
* Fluent Forms: supports the current `fluentform/submission_inserted` hook. Contact Form 7: checkbox fields no longer produce "Array".

**Tracking**
* `data-ppt-cta` / `data-ppt-label` attributes as an alternative to CSS classes.
* `file_download` and `video_start` / `video_progress` / `video_complete` events.
* Fixed `form_submit` double-counting in Auto mode (generic fallback now skips forms a supported plugin handles) and stopped counting search/login/comment forms.
* Events fired before gtag.js finishes loading are queued in `dataLayer` instead of dropped.

**Admin / operations**
* Status panel: Measurement ID check, duplicate-gtag detection (Site Kit, MonsterInsights, ExactMetrics, GTM4WP), detected form plugins, lead delivery health.
* WP-CLI: `wp ppt status | test-lead | queue | get | set`.
* `PPT_LEADS_API_KEY`, `PPT_LEADS_ENDPOINT`, `PPT_MEASUREMENT_ID` constants and `ppt_settings`, `ppt_lead_payload`, `ppt_tracker_config`, `ppt_download_extensions` filters.
* Requirements raised to WordPress 5.9 / PHP 8.0 (the code already depended on `str_contains`).

= 1.4.0 =
* Added Elementor Pro Forms support — GA4 `form_submit` now fires on Elementor's `submit_success` (genuine successes only), and lead capture hooks `elementor_pro/forms/new_record`. Previously Elementor submissions reached GA4 only via the generic fallback (which counted failed and spam-blocked attempts) and never reached the Leads API at all.
* Fixed CTA click tracking missing buttons in nested page-builder markup. The matcher walked only 3 ancestors, so builders that wrap buttons more deeply (Elementor nests 5-6 levels) never fired `cta_click`. Clicks are now matched by nearest ancestor with no depth limit, scoped to actual controls so a CTA class on a wrapper cannot turn every click inside it into an event.
* CTA class settings now tolerate multi-class values ("btn btn--action") and stray leading dots, and an unusable value is skipped instead of throwing on every click.
* `cta_click` now reports `link_url` correctly when the CTA class sits on a wrapper rather than the link itself.

= 1.0.0 =
* Initial release.
