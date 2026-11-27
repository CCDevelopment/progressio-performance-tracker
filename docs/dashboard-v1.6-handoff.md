# Hand-off: Dashboard changes for Performance Tracker v1.7.0

**For:** the Progressio dashboard (`https://dashboard.progressiodev.com/api/…`)
**From:** progressio-performance-tracker plugin v1.7.0 and `@progressio/performance-tracker` (Node) 1.7.0. (v1.6.0 was the SEO meta release; its dashboard notes are in section 6 of the leads hand-off below. This file keeps its v1.6 name so existing links still work.)
**Builds on:** [dashboard-leads-api-handoff.md](dashboard-leads-api-handoff.md) (v1.5.0), which still describes the lead payload and response contract.

Three changes. The first two are small; the third is the new spam-rules feature and needs a new endpoint plus an admin screen.

---

## 1. The API key has left the request body

v1.5.0 and v1.6.0 sent the key twice: `Authorization: Bearer <key>` and `apiKey` in the JSON body. v1.7.0 sends only the header. Nothing else about the request changed. If the endpoint still reads `apiKey` from the body as a fallback, that path is dead code for 1.7+ sites and can go once no site runs an older version.

## 2. New lead payload keys

All optional; absent when empty, as before.

| Key | Type | Notes |
|---|---|---|
| `spamReason` | string ≤40 | Why `isSpam` is true. Currently always `blocklist` (the plugin's own rules). Form-plugin verdicts (WS Form spam level, Gravity Forms status) still arrive without a reason, as in 1.5. |
| `spamRule` | string ≤220 | `type:rule`, e.g. `phrases:guest post`, `domains:spammer.example`, `emails:joe@gmail.example`, `phones:8054002077`. Phone rules are digits only, leading `1` stripped. |
| `blocked` | `true` | The form plugin rejected the submission because of this rule: the visitor saw an error and the client was **not** emailed. Absent when the submission went through (the plugin only flagged it) and on every non-spam lead. |
| `formPlugin` | enum | Gains `bricks` (Bricks Builder native forms) and `node` (the Node package's default). |

### What to do with them

- **Quarantine, don't drop.** A `blocked: true` lead is the only record of that submission anywhere. Keep it out of the client-facing inbox and out of counts, but show it under a "Blocked spam" tab or filter where Chris can see the rule that caught it and release it if it was a real enquiry.
- **`isSpam: true` without `blocked`** means the form plugin delivered it (the client got the email) and the plugin flagged it afterwards. Label it rather than hide it.
- **Group by `spamRule`.** "Which rules are firing, how often, on which sites" is how the global list gets tuned, and a rule that only ever catches real enquiries should be visible as such.
- `generate_lead` in GA4 never fires for spam, so GA4 lead counts and inbox counts will agree only if spam is excluded on the dashboard too.

## 3. New endpoint: `GET /api/spam-rules`

The plugin pulls the global block list from here. Same authentication as `/api/leads`.

### Request

| Header | Value |
|---|---|
| `Authorization` | `Bearer <leads api key>` (the client site's key) |
| `Accept` | `application/json` |
| `X-PPT-Version` | plugin / package version |
| `User-Agent` | `ProgressioPerformanceTracker/1.7.0 (https://client-site.com)` |

No query parameters. The plugin fetches at most twice a day per site (WP-Cron), plus on demand from its settings page. Node sites fetch once per function instance and cache for six hours. A `4xx`/`5xx` or timeout makes the site keep its last good copy and try again later (10 minutes on Node, next cron on WordPress), so the endpoint being down costs nothing but staleness.

### Response: `200 application/json`

```json
{
  "updatedAt": "2026-11-03T10:00:00Z",
  "phrases": ["local seo", "seo audit", "guest post", "answer engine optimization", "ai search visibility"],
  "domains": ["jmailservice.com", "invincibldev.com"],
  "emails":  ["someone@gmail.com"],
  "phones":  ["8054002077", "7077060205"]
}
```

- Four arrays of plain strings. Every key optional; an empty list is fine. Order does not matter.
- **Literal strings only, no regex.** The plugin matches them itself: phrases whole-word and case-insensitive (spaces/hyphens interchangeable), domains anywhere in a value including subdomains and URLs, emails exact, phones by digits in any formatting. Anything you send is normalised again on the site, so casing, `+1`, brackets and `https://` prefixes are all tolerated, but keep the stored data clean.
- Also accepted, for convenience: `keywords` as an alias for `phrases`; items as `{ "value": "…" }` objects; the whole thing wrapped in `data` or `rules`. Not needed; the flat shape above is preferred.
- `updatedAt` is shown on the site's settings page. Any ISO-8601 string.
- Cap at ~1000 entries per type; the plugin ignores anything beyond that.

### Scope

Return **one global list** for every key. The plugin applies the client's own additions and exceptions on the site, so the endpoint does not need per-client logic. If per-client overrides ever move into the dashboard, return the merged list for the key's client from the same endpoint and nothing on the plugin side has to change.

### Suggested admin screen

A single "Spam rules" page, one table per type, add/remove rows, with a note of which `spamRule` values have fired recently (from §2) beside each rule. That closes the loop: a pitch gets through, Chris sees it in the blocked/flagged view, adds its phrase or domain here, and every site picks it up within twelve hours.

## 4. Suggested work, in priority order

1. **`/api/spam-rules`** returning the four lists (even hard-coded to start). Until it exists, sites log `HTTP 404` on the settings page and enforce only their site-level rules.
2. **Store `spamReason`, `spamRule`, `blocked`;** exclude `blocked` leads from the inbox and from counts.
3. **Blocked / flagged view** with release-to-inbox.
4. **Rules admin screen** with per-rule hit counts.
5. Drop the body `apiKey` fallback once every site reports `pluginVersion` ≥ 1.7.0.
