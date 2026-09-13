# Hand-off: Dashboard changes for Performance Tracker v1.5.0

**For:** the Progressio dashboard (`https://dashboard.progressiodev.com/api/leads`)
**From:** progressio-performance-tracker plugin, branch `feature/v1.5.0-security-and-attribution`
**Why:** v1.5.0 changes what the plugin POSTs to the Leads API. Existing keys are unchanged, so the current endpoint will keep working if it tolerates unknown keys — but the new data is only useful once the dashboard stores and surfaces it, and two behaviours (retry + response ID) need the endpoint to cooperate.

---

## 1. The request

`POST {endpoint}` — JSON body, one lead per request.

| Header | Value | Notes |
|---|---|---|
| `Content-Type` | `application/json` | |
| `Accept` | `application/json` | |
| `Authorization` | `Bearer <leads api key>` | **New.** Preferred auth going forward. |
| `X-PPT-Version` | `1.5.0` | **New.** Plugin version, for compatibility gating. |
| `User-Agent` | `ProgressioPerformanceTracker/1.5.0 (https://client-site.com)` | |

`apiKey` is **still sent in the body** for backwards compatibility. Once the API accepts the `Authorization` header, tell me and the plugin will stop sending it in the body (v1.6).

Delivery is now **blocking with a 5 s timeout** from the visitor's form submission, so keep the endpoint fast — accept, enqueue internally if you must, respond.

## 2. Payload

Keys marked ⭐ are new in 1.5.0. Any key whose value is empty/null is **omitted**, so treat every key as optional. Strings are pre-sanitised and length-capped by the plugin, but validate server-side anyway.

### Contact

| Key | Type | Cap | Notes |
|---|---|---|---|
| `name` | string | 150 | |
| `email` | string | | Lower-cased, passed `is_email()`. At least one of `email` / `phone` is always present. |
| `phone` | string | 40 | 6–20 digits present. |
| `message` ⭐ | string | 2000 | Textarea / "how can we help" field. |

### Attribution (top-level = "last non-direct touch")

`source`/`medium`/… describe the session's touch when it has one, otherwise the visitor's **first** touch. This matches GA4's convention and is what the old `source`/`medium` effectively meant.

| Key | Type | Notes |
|---|---|---|
| `source`, `medium`, `campaign`, `keyword`, `content` ⭐ | string | `keyword` = `utm_term`. |
| `channel` ⭐ | enum | `Paid Search`, `Paid Social`, `Display`, `Organic Search`, `Organic Social`, `Email`, `Affiliate`, `SMS`, `Referral`, `Direct`. Always present. |
| `gclid`, `fbclid`, `msclkid` ⭐, `ttclid` ⭐, `liFatId` ⭐, `dclid` ⭐ | string | Ad click IDs. |
| `landingPage` | URL | **Semantics changed:** now the visitor's *first-ever* landing page (was: the submitting page). Path + marketing params only — never other query strings. |
| `sessionLandingPage` ⭐ | URL | First page of the current session. |
| `pageUrl` | URL | The page the form was submitted from (from the HTTP referer, only if on the client's own host). |
| `firstTouch` ⭐ | object | `{ source, medium, campaign, term, content, channel, gclid…, referrer, landingPage, at }` — all optional. `at` is ISO-8601. |
| `lastTouch` ⭐ | object | Same shape. `channel: "Direct"` with nothing else means a returning direct visit. |
| `firstTouchAt` ⭐ | ISO-8601 | When the first-touch cookie was created (max 365 days back, default window 90). |

### Identity / dedupe

| Key | Type | Notes |
|---|---|---|
| `visitorId` ⭐ | UUID | Stable per browser for the attribution window (first-party cookie). Also sent to GA4 as `ppt_visitor_id`, so it joins GA4 events to leads. |
| `submissionId` ⭐ | UUID | Unique per submission attempt. **Use as an idempotency key** — see §3. |
| `contactHash` ⭐ | sha256 hex (64) | `sha256(lower(email) + "|" + digits(phone))`. Group repeat submitters without comparing PII. |
| `ipHash` ⭐ | hex (32) | Salted, truncated hash of the visitor IP. Per-site salt, so only comparable within one client site. |
| `userAgent` ⭐ | string | ≤255. |

### Form / spam

| Key | Type | Notes |
|---|---|---|
| `formId`, `formTitle` | string | Unchanged. |
| `formPlugin` ⭐ | enum | `wsform`, `gravityforms`, `wpforms`, `cf7`, `fluentforms`, `formidable`, `elementor`, `ninja`, `test`. |
| `isSpam` ⭐ | bool | Only when the form plugin reports it (Gravity Forms status, WS Form spam level ≥50, CF7 always `false` because it only fires on non-spam). Absent = unknown. |
| `spamScore` ⭐ | int 0–100 | WS Form only. |

### Meta

| Key | Type | Notes |
|---|---|---|
| `siteUrl` ⭐ | URL | Client site `home_url()`. Handy for validating the key belongs to that site. |
| `pluginVersion` ⭐ | string | |
| `submittedAt` ⭐ | ISO-8601 UTC | |
| `test` ⭐ | `true` | Only present on test leads from the settings page / `wp ppt test-lead`. Name is `PPT Test Lead`, email `test+<ts>@example.com` unless overridden. |

### Example

```json
{
  "apiKey": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "+44 7700 900123",
  "message": "Looking for a quote on a kitchen refit.",
  "source": "google",
  "medium": "cpc",
  "campaign": "spring-kitchens",
  "keyword": "kitchen fitters leeds",
  "channel": "Paid Search",
  "gclid": "CjwKCAjw...",
  "landingPage": "https://client.com/kitchens/?gclid=CjwKCAjw...",
  "sessionLandingPage": "https://client.com/",
  "pageUrl": "https://client.com/contact/",
  "firstTouch": { "source": "google", "medium": "cpc", "campaign": "spring-kitchens", "channel": "Paid Search", "gclid": "CjwKCAjw...", "landingPage": "https://client.com/kitchens/?gclid=CjwKCAjw...", "at": "2026-09-01T10:12:04+00:00" },
  "lastTouch": { "channel": "Direct", "landingPage": "https://client.com/", "at": "2026-09-13T08:40:00+00:00" },
  "firstTouchAt": "2026-09-01T10:12:04+00:00",
  "visitorId": "4c042656-4d75-4ab6-af9a-3579ad94239e",
  "submissionId": "b1f2d7a0-6c3e-4b9a-9e11-2f0c7d5a8e44",
  "contactHash": "9b74c9897bac770ffc029102a200c5de…",
  "ipHash": "3f1a9c0e7b2d4e5f6a7b8c9d0e1f2a3b",
  "userAgent": "Mozilla/5.0 …",
  "formId": "12",
  "formTitle": "Contact Us",
  "formPlugin": "wsform",
  "isSpam": false,
  "spamScore": 0,
  "siteUrl": "https://client.com",
  "pluginVersion": "1.5.0",
  "submittedAt": "2026-09-13T08:41:17+00:00"
}
```

## 3. Response contract (this is the part that needs the endpoint's cooperation)

| Status | Plugin behaviour |
|---|---|
| `2xx` | Success. Response JSON is parsed for a lead ID at `id`, `leadId` or `lead_id` — top-level, or under `data` / `lead`. That ID is fed back to GA4 as `lead_id` on a `generate_lead` event, so **please return one**. |
| `4xx` (except 408/425/429) | Treated as a bad request — **not retried**, error shown on the plugin's status panel. Return a short plain-text or JSON message; the first 200 chars are displayed to the site admin. |
| `408`, `425`, `429`, `5xx`, network error / timeout | **Retried** via WP-Cron with backoff (5 min → 15 min → 1 h → 6 h → 24 h, 5 attempts total). |

Consequences for the dashboard:

- **Idempotency:** a request the API accepted but whose response was lost (timeout at 5 s) will be resent with the same `submissionId`. Upsert on `submissionId` and return the existing lead's ID rather than creating a duplicate.
- Return `2xx` **quickly**. If lead processing is slow (enrichment, notifications), accept and defer.
- Don't return `5xx` for validation problems — that just makes the plugin hammer you for 24 hours.

## 4. Suggested dashboard work, in priority order

1. **Tolerate unknown keys** on the leads endpoint (if it doesn't already). Without this, v1.5.0 sites get rejected.
2. **Return the lead ID** in the 2xx response (`{ "id": "…" }` is enough).
3. **Upsert on `submissionId`**; also index `visitorId` and `contactHash`.
4. **Accept `Authorization: Bearer`** as an alternative to body `apiKey` — then the body key can be retired.
5. **Store and show the new fields:** `channel` (filter in the Leads Inbox), `message`, `formPlugin`, `firstTouch` / `lastTouch` (a "journey" panel on the lead), `sessionLandingPage`, click IDs.
6. **`test: true` leads** — keep them out of the client-facing inbox (or label them) and out of reporting counts.
7. **`isSpam` / `spamScore`** — flag rather than drop; the form plugin has already decided what to deliver.
8. **Repeat-submitter grouping** via `contactHash`; "returning visitor" badge via `visitorId` seen before.
9. Optional: validate that `siteUrl`'s host matches the client record the API key belongs to, and log `pluginVersion` per site so you can see who's on old versions.

## 5. GA4 join

GA4 events from the same site carry `ppt_visitor_id` (= `visitorId`), `form_id`, `traffic_channel`, `first_channel`, and — on `generate_lead` — `lead_id` (whatever the API returned). If the dashboard ever pulls GA4 data, those are the join keys.
