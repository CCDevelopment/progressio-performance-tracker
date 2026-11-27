# Brief: build the spam-rules endpoint in PDS Task Dash

Paste this into a Claude Code session opened in `~/Desktop/Projects/PDS Task Dash`. Read that repo's `CLAUDE.md` first (section 0, then "Migrations: binding procedure" and "Git and deploy gotchas"), then do the work below.

## Why

Progressio Performance Tracker v1.7.0 (plugin repo: `~/Desktop/Projects/Progressio Dev Performance Tracker Plugin`, branch `feature/node-package`, PR #1) adds a spam filter to every client site. Each site pulls a **global block list** from the dashboard, merges in its own additions and exceptions, and rejects matching form submissions before the client is emailed. Rejected submissions are still POSTed to `/api/leads` flagged as blocked spam.

The dashboard side does not exist yet. Until it does, every site logs `HTTP 404` for the global list and enforces only its own rules. This brief is the dashboard half. Full background: `docs/dashboard-v1.6-handoff.md` in the plugin repo.

## What already exists in this repo

- `src/app/api/leads/route.ts`: the leads endpoint. Has `bearerToken()`, `rateLimit()`, Zod schemas with `str()`, `looseBool`, lenient-by-design validation, and the `Client` lookup by `leadsApiKey`. Reuse all of it.
- `prisma/schema.prisma`: `Client.leadsApiKey String? @unique` (line ~59); `Lead` model (line ~239) already has `isSpam Boolean?` and `spamScore Int?`.
- `src/components/LeadsInbox.tsx` and `src/app/api/client-portal/leads/route.ts`: where leads are listed, including the client-facing portal.
- Migrations follow the binding procedure in `CLAUDE.md`. Do not freehand `prisma db push`.

## Task 1: `GET /api/spam-rules`

New route `src/app/api/spam-rules/route.ts`.

**Auth:** `Authorization: Bearer <leadsApiKey>`, resolved to a `Client` exactly as `/api/leads` does. No body `apiKey` fallback (v1.7 never sends one). Unknown key: `401`. Missing header: `401`. Rate limit with the same `rateLimit()` helper, generous (sites fetch at most twice a day each; `60/min` per key is plenty).

**Response:** `200`, `application/json`, flat shape:

```json
{
  "updatedAt": "2026-11-03T10:00:00.000Z",
  "phrases": ["local seo", "seo audit", "guest post"],
  "domains": ["jmailservice.com", "invincibldev.com"],
  "emails":  [],
  "phones":  ["8054002077", "7077060205"]
}
```

- Four arrays of plain strings, every key always present (empty array when none). `updatedAt` is the most recent rule change, ISO-8601.
- **Literal strings only, never regex.** The plugin does its own matching: phrases whole-word and case-insensitive with spaces and hyphens interchangeable; domains anywhere in a value including subdomains and URLs; emails exact; phones by digits in any formatting.
- Store rules already normalised: phrases lower-cased and whitespace-collapsed; domains lower-cased, no scheme, no `www.`, no path; emails lower-cased; phones digits only with a leading US `1` stripped (so `+1 (805) 400-2077` is stored as `8054002077`). The plugin normalises again on receipt, so a stray `+1` is tolerated, but keep the data clean.
- One global list for every key. Per-client overrides live on the sites, so this endpoint has no per-client logic. (If per-client overrides ever move here, return the merged list for the key's client from this same endpoint; the plugin needs no change.)
- Add `Cache-Control: private, max-age=300`. CORS headers as in `/api/leads` but `GET, OPTIONS`.
- Cap at 1000 entries per type.

**Headers the plugin sends,** for logging: `X-PPT-Version` (plugin or Node package version) and `User-Agent: ProgressioPerformanceTracker/1.7.0 (https://client-site.com)`.

## Task 2: storage and seed

New model, one row per rule:

```prisma
model SpamRule {
  id        String   @id @default(cuid())
  type      SpamRuleType
  value     String
  note      String?          // where it came from, e.g. "invincibldev pitch, Oct 2026"
  createdAt DateTime @default(now())
  updatedAt DateTime @updatedAt
  @@unique([type, value])
}

enum SpamRuleType { phrase domain email phone }
```

Seed with Chris's current list (these were hard-coded in a WS Form snippet on one site and are the reason this feature exists):

- **phrases:** local seo, seo services, seo audit, free audit, no-obligation audit, google visibility, first page of google, rank higher, search rankings, backlinks, guest post, top of search results, keyword options, no long setup, aeo, answer engine optimization, generative engine optimization, geo services, geo optimization, aeo and geo, geo and aeo, ai search visibility, ai search optimization, ai overviews, llm optimization, llm visibility, chatgpt visibility, chatgpt rankings, lead generation services, digital marketing agency
- **domains:** jmailservice.com, invincibldev.com
- **phones:** 8054002077, 7077060205

Note: `progressiodev.com` and any client that sells marketing will exempt the SEO and AI-search phrases on their own site via the plugin's "Never block on this site" box. Do not try to solve that here.

## Task 3: accept the new lead payload keys

In `src/app/api/leads/route.ts` and the `Lead` model, add:

| Key | Type | Meaning |
|---|---|---|
| `spamReason` | string ≤40 | Why `isSpam` is true. v1.7 always sends `blocklist`. |
| `spamRule` | string ≤220 | `type:rule` that matched, e.g. `phrases:guest post`, `domains:spammer.example`, `phones:8054002077`. |
| `blocked` | boolean | `true` when the form plugin **rejected** the submission: the visitor saw an error and the client was not emailed. Absent otherwise. |

Also: `formPlugin` gains the values `bricks` and `node`. The body `apiKey` is no longer sent by 1.7 sites (1.6.0 and earlier still send it); keep the fallback for now and drop it once every site reports `pluginVersion >= 1.7.0`.

Behaviour:

- **Quarantine, don't drop.** A `blocked: true` lead is the only record of that submission anywhere. Exclude it from the client portal inbox (`client-portal/leads`) and from lead counts and reports, but keep it visible to the team.
- `isSpam: true` **without** `blocked` means the client did get the email and the plugin flagged it afterwards. Label it rather than hide it.
- `generate_lead` never fires in GA4 for spam, so GA4 and inbox counts only agree if spam is excluded here too.

## Task 4: admin UI (team app, `work.progressiodev.com`)

Minimum viable, in this order:

1. **Spam rules page:** one table per type, add and remove rows, with the note field. Normalise on save as described in Task 1 and reject duplicates (the unique constraint will catch them anyway).
2. **Blocked / flagged view** on the leads inbox: a filter or tab for `isSpam = true`, showing `spamRule`, `blocked` and the site. Include a "Release to inbox" action that sets `isSpam = false` and clears `blocked`, for false positives.
3. Beside each rule on the rules page, a count of leads whose `spamRule` matches it in the last 30 days. That is how the list gets tuned.

## Acceptance

```bash
# 401 without a key
curl -si https://dashboard.progressiodev.com/api/spam-rules | head -1

# 200 with a real client key, four arrays present
curl -s -H "Authorization: Bearer <a client leadsApiKey>" https://dashboard.progressiodev.com/api/spam-rules | jq 'keys'
```

Then on a WordPress site running plugin 1.7.0 with that key: Perf Tracker settings, Spam Filter section, "Refresh global list now" reports the rule count and "Show every rule in force" lists them. `wp ppt spam --refresh` does the same from the CLI. Submit a test form containing "guest post": the form shows "Sorry, this message could not be sent." and the lead appears in the dashboard with `blocked: true` and `spamRule: phrases:guest post`, outside the client portal inbox.

## Out of scope

- Per-client rule overrides in the dashboard (the sites handle it).
- Regex or scored rules. Literal strings only; the sites must never evaluate dashboard-supplied patterns.
- Changing the plugin. The contract above is fixed by plugin v1.7.0; if something here cannot be met, say so rather than improvising a different shape.

## Copy rule

No em dashes anywhere, including code comments and commit messages. Check with `grep -rn $'\xe2\x80\x94' <changed files>` (the UTF-8 bytes of the em dash) before committing.
