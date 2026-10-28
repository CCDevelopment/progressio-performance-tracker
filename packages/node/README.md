# @progressio/performance-tracker

The Progressio Performance Tracker for Node sites: Next.js, Astro, or anything
that ends in a Vercel function. It does what the WordPress plugin does, the
same way:

- **Same browser tracker.** `tracker.js` is copied from the plugin's
  `assets/tracker.js` at publish time, so GA4 events, CTA tracking, attribution
  cookies (`ppt_ft`, `ppt_lt`) and channel rules are identical on both.
- **Same leads.** `captureLead()` is a port of the plugin's lead code: same
  contact extraction, same payload, same `submissionId` dedupe, same
  `ppt_last_lead` handshake so the browser fires `generate_lead` with the
  dashboard's lead id.
- **Retries.** The first attempt happens inline (5s cap, as on WordPress).
  Timeouts, 408/425/429 and 5xx are retried in the background with
  `waitUntil()`. 4xx is never retried.

Versions move together with the plugin: package 1.6.0 ships the 1.6.0 tracker.

## Install

```bash
npm i @progressio/performance-tracker
```

Set these on the site's Vercel project (Production, Preview and Development):

| Variable | Value |
|---|---|
| `PPT_LEADS_API_KEY` | The client's Leads API key (dashboard: client, Services). Server only, never `NEXT_PUBLIC_`. |
| `PPT_SITE_URL` | `https://www.example.com`. Optional; defaults to the request host. |

### 1. Tracker on every page

Astro, in the base layout's `<head>`:

```astro
---
import { trackerHead } from '@progressio/performance-tracker';
---
<Fragment set:html={trackerHead({ measurementId: 'G-XXXXXXX' })} />
```

Next.js, in `app/layout.tsx`:

```tsx
import { PerformanceTracker } from '@progressio/performance-tracker/next';

<body>
  {children}
  <PerformanceTracker measurementId="G-XXXXXXX" />
</body>
```

If the site already loads gtag.js itself, pass `loadGtag: false` so it isn't
loaded twice. Other options mirror the WordPress settings page
(`ctaClasses`, `attributionDays`, `trackScroll`, `debug`, and so on).

### 2. Capture the lead where the form is handled

Vercel function (`api/contact.js`):

```js
import { captureLead, applyLeadCookie } from '@progressio/performance-tracker';

const lead = await captureLead(req, data, { formId: 'booking', formTitle: 'Book a food cart' });
applyLeadCookie(res, lead);
```

Next.js route handler or Astro endpoint:

```js
const lead = await captureLead(request, data, { formId: 'contact', formTitle: 'Contact' });
const res = Response.json({ ok: true });
applyLeadCookie(res, lead);
return res;
```

Next.js server action:

```js
import { cookies, headers } from 'next/headers';

const lead = await captureLead(await headers(), data, { formId: 'contact' });
if (lead.cookie) (await cookies()).set(lead.cookie.name, lead.cookie.value, lead.cookie.options);
```

`data` is the form as a plain object (`{ first_name, email, phone, ... }`) or a
list of `{ id, label, type, value }`. Name, email, phone and message are found
by the same rules as on WordPress. Pass `fieldMap: { name: 'first|last' }` when
they guess wrong.

`captureLead()` never throws. Send the visitor's email (Resend, etc.) whatever
it returns. A form with no email and no phone is skipped, as on WordPress.

### 3. Tell the tracker the form went through

After the form's `fetch()` succeeds:

```js
window.ppt?.formSubmitted('booking', 'Book a food cart');
```

That fires `form_submit` and, when the lead cookie from step 2 is there,
`generate_lead` with the lead id. Full-page form posts need nothing: the
tracker picks the cookie up on the next page load.

### 4. Check it

```bash
npx ppt test --env-file .env.local
```

Sends a `test: true` lead. The dashboard stores it but keeps it out of the
client's inbox, form-fill count and GA4. If the key pulls as `[SENSITIVE]`, add
a Development value in Vercel or test on a Preview deployment.

## Releasing

From `packages/node`, after the tracker or this package changes:

1. Bump `version` in `package.json` and `src/version.js` (the tests check they
   match).
2. `npm test`
3. `npm publish`. `prepack` copies the current `assets/tracker.js` in first.

The `packages/` folder is `export-ignore`d, so it never reaches WordPress sites
through the plugin's GitHub updater.
