// Port of the delivery half of PPT_Leads (includes/class-ppt-leads.php):
// submission → payload → Progressio Leads API, with retries.
//
// The WordPress plugin retries through a WP-Cron queue for 24 hours. Serverless
// functions have no cron, so here the first attempt is made inline (the form
// response waits for it, as it does on WordPress, so the lead id can be handed
// to tracker.js) and transient failures are retried in the background with
// waitUntil() for as long as the function is allowed to live. `onFailure` is
// the hook for a durable queue later; nothing calls it except a lead that
// finally could not be delivered.

import { createHash, randomUUID } from 'node:crypto';
import { waitUntil as vercelWaitUntil } from '@vercel/functions';
import { COOKIE_LEAD, forLead, parseCookies, siteUrlForPath, submittingPageUrl } from './attribution.js';
import { extractContact, normaliseFields } from './contact.js';
import { sanitizeText } from './sanitize.js';
import { DEFAULT_ENDPOINT, VERSION } from './version.js';

/** Background retry delays (ms) after the inline attempt fails transiently. */
const RETRY_DELAYS = [2000, 8000, 20000];

// ── Request access ───────────────────────────────────────────────────────────

/**
 * Header reader for whatever the site has to hand: a Web Request (Next route
 * handlers, Astro endpoints), Node's IncomingMessage (Vercel /api functions),
 * a Headers object (Next's headers()), or a plain object of headers.
 */
function headerReader(source) {
  const h = source && typeof source === 'object' && 'headers' in source && source.headers ? source.headers : source;
  if (h && typeof h.get === 'function') return (name) => h.get(name) ?? '';
  if (h && typeof h === 'object') {
    return (name) => {
      const v = h[name.toLowerCase()] ?? h[name];
      return Array.isArray(v) ? v.join(', ') : String(v ?? '');
    };
  }
  return () => '';
}

function clientIp(header, source) {
  const fwd = header('x-forwarded-for').split(',')[0].trim();
  return fwd || header('x-real-ip').trim() || String(source?.socket?.remoteAddress ?? '');
}

function siteUrlFrom(options, header) {
  const configured = options.siteUrl || process.env.PPT_SITE_URL;
  if (configured) return String(configured).replace(/\/+$/, '');
  const host = header('x-forwarded-host').split(',')[0].trim() || header('host');
  if (!host) return '';
  const proto = header('x-forwarded-proto').split(',')[0].trim() || 'https';
  return `${proto}://${host}`;
}

// ── Payload ──────────────────────────────────────────────────────────────────

function touchForPayload(siteUrl, touch) {
  if (!touch || !Object.keys(touch).length) return {};
  const out = {};
  for (const k of ['source', 'medium', 'campaign', 'term', 'content', 'channel', 'gclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'dclid', 'referrer']) {
    if (touch[k]) out[k] = touch[k];
  }
  if (touch.landing_page) out.landingPage = siteUrlForPath(siteUrl, touch.landing_page);
  if (touch.timestamp) out.at = isoSeconds(new Date(touch.timestamp * 1000));
  return out;
}

/** gmdate( 'c' ) equivalent: second precision, explicit UTC offset. */
function isoSeconds(date) {
  return date.toISOString().replace(/\.\d{3}Z$/, '+00:00');
}

const isEmpty = (v) => v === null || v === undefined || v === '' || (typeof v === 'object' && !Object.keys(v).length);

/**
 * Build the Leads API payload. Mirrors PPT_Leads::build_payload(); the one
 * deliberate difference is that the API key travels only in the
 * Authorization header, never in the body.
 */
export function buildPayload({ contact, attribution, siteUrl, pageUrl, ip, userAgent, hashSalt, formId, formTitle, formPlugin, isSpam, spamScore, test }) {
  const { first, last } = attribution;

  // Top-level source/medium describe the "last non-direct" touch: the
  // session's touch when it has one, otherwise the visitor's first touch.
  const touch = last.source || last.gclid || last.fbclid ? last : Object.keys(first).length ? first : last;

  const email = contact.email ?? '';
  const phone = contact.phone ?? '';

  const payload = {
    name: contact.name ?? null,
    email: email || null,
    phone: phone || null,
    message: contact.message ?? null,

    source: touch.source ?? null,
    medium: touch.medium ?? null,
    campaign: touch.campaign ?? null,
    keyword: touch.term ?? null,
    content: touch.content ?? null,
    channel: touch.channel ?? 'Direct',
    gclid: touch.gclid ?? null,
    fbclid: touch.fbclid ?? null,
    msclkid: touch.msclkid ?? null,
    ttclid: touch.ttclid ?? null,
    liFatId: touch.li_fat_id ?? null,
    dclid: touch.dclid ?? null,

    landingPage: siteUrlForPath(siteUrl, first.landing_page ?? last.landing_page ?? null),
    sessionLandingPage: siteUrlForPath(siteUrl, last.landing_page ?? null),
    pageUrl: pageUrl ?? null,
    firstTouch: touchForPayload(siteUrl, first),
    lastTouch: touchForPayload(siteUrl, last),
    firstTouchAt: first.timestamp ? isoSeconds(new Date(first.timestamp * 1000)) : null,

    visitorId: first.visitor_id ?? null,
    submissionId: randomUUID(),
    contactHash: email || phone ? createHash('sha256').update(`${email.toLowerCase()}|${phone.replace(/\D+/g, '')}`).digest('hex') : null,
    ipHash: ip && hashSalt ? createHash('sha256').update(ip + hashSalt).digest('hex').slice(0, 32) : null,
    userAgent: userAgent ? sanitizeText(userAgent).slice(0, 255) : null,

    formId: formId || null,
    formTitle: formTitle || null,
    formPlugin,
    isSpam: typeof isSpam === 'boolean' ? isSpam : null,
    spamScore: spamScore ?? null,

    siteUrl: siteUrl || null,
    pluginVersion: VERSION,
    submittedAt: isoSeconds(new Date()),
    test: test === true ? true : null,
  };

  return Object.fromEntries(Object.entries(payload).filter(([, v]) => !isEmpty(v)));
}

// ── Delivery ─────────────────────────────────────────────────────────────────

function leadIdFromBody(body) {
  let data;
  try {
    data = JSON.parse(body);
  } catch {
    return '';
  }
  if (!data || typeof data !== 'object') return '';
  for (const k of ['leadId', 'lead_id', 'id']) {
    for (const scope of [data, data.data, data.lead]) {
      const v = scope && typeof scope === 'object' ? scope[k] : undefined;
      if (v !== undefined && v !== null && typeof v !== 'object') return sanitizeText(String(v)).slice(0, 64);
    }
  }
  return '';
}

/**
 * One POST to the Leads API. Never throws.
 *
 * @returns {Promise<{ ok: boolean, code: number, error: string, leadId: string, retry: boolean }>}
 */
export async function sendLead(payload, { apiKey, endpoint, siteUrl = '', timeoutMs = 5000, fetch: fetchImpl = globalThis.fetch } = {}) {
  if (!apiKey) return { ok: false, code: 0, error: 'Leads API not configured: set PPT_LEADS_API_KEY.', leadId: '', retry: false };

  let res;
  try {
    res = await fetchImpl(endpoint || DEFAULT_ENDPOINT, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        Authorization: `Bearer ${apiKey}`,
        'X-PPT-Version': VERSION,
        'User-Agent': `ProgressioPerformanceTracker/${VERSION} (${siteUrl || 'node'}; node)`,
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(timeoutMs),
    });
  } catch (e) {
    return { ok: false, code: 0, error: e?.name === 'TimeoutError' ? 'Timed out' : String(e?.message || e), leadId: '', retry: true };
  }

  const body = await res.text().catch(() => '');
  if (res.status >= 200 && res.status < 300) {
    return { ok: true, code: res.status, error: '', leadId: leadIdFromBody(body), retry: false };
  }

  // 4xx (other than rate-limit/timeout) means the request itself is bad —
  // retrying the same payload won't help.
  const retry = res.status >= 500 || [408, 425, 429].includes(res.status);
  const error = `HTTP ${res.status}: ${sanitizeText(body).slice(0, 200) || res.statusText}`;
  return { ok: false, code: res.status, error, leadId: '', retry };
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function retryInBackground(payload, sendOptions, delays, onFailure, log) {
  let last;
  for (const delay of delays) {
    await sleep(delay);
    last = await sendLead(payload, sendOptions);
    if (last.ok) {
      log('info', `Lead delivered on retry${last.leadId ? ` (${last.leadId})` : ''}.`);
      return;
    }
    if (!last.retry) break;
  }
  log('error', `Lead could not be delivered: ${last?.error ?? 'unknown error'}`);
  if (onFailure) await Promise.resolve(onFailure(payload, last)).catch(() => {});
}

// ── Lead cookie ──────────────────────────────────────────────────────────────

/**
 * Tells tracker.js the Leads API accepted a lead so it can fire GA4's
 * generate_lead. Value is the dashboard's lead id, or "1" when the API
 * returned none. Short-lived and readable by JavaScript by design.
 */
function leadCookie(leadId, secure) {
  const value = leadId || '1';
  return {
    name: COOKIE_LEAD,
    value,
    options: { path: '/', maxAge: 300, sameSite: 'lax', httpOnly: false, secure },
    header: `${COOKIE_LEAD}=${encodeURIComponent(value)}; Path=/; Max-Age=300; SameSite=Lax${secure ? '; Secure' : ''}`,
  };
}

/**
 * Add the lead cookie to a response. Works with Node's ServerResponse (Vercel
 * /api functions), a Headers object, or a Web Response.
 */
export function applyLeadCookie(target, result) {
  if (!result?.cookie || !target) return;
  const header = result.cookie.header;
  const headers = typeof target.append === 'function' ? target : target.headers;
  if (headers && typeof headers.append === 'function' && typeof target.setHeader !== 'function') {
    headers.append('Set-Cookie', header);
    return;
  }
  if (typeof target.appendHeader === 'function') {
    target.appendHeader('Set-Cookie', header);
  } else if (typeof target.setHeader === 'function') {
    const existing = target.getHeader?.('Set-Cookie');
    target.setHeader('Set-Cookie', existing ? [].concat(existing, header) : header);
  }
}

// ── Entry point ──────────────────────────────────────────────────────────────

/**
 * Turn a form submission into a lead on the Progressio dashboard.
 *
 * Never throws and never blocks longer than one API call (5s by default), so
 * it is safe to await in a form handler before replying to the visitor.
 *
 * @param {any} request Web Request, Node IncomingMessage, Headers, or plain headers object.
 * @param {Record<string, unknown> | Array<{id: string, label?: string, type?: string, value: unknown}>} fields
 * @param {import('../index').CaptureOptions} [options]
 * @returns {Promise<import('../index').CaptureResult>}
 */
export async function captureLead(request, fields, options = {}) {
  const log = makeLogger(options);
  try {
    const header = headerReader(request);
    const apiKey = options.apiKey ?? process.env.PPT_LEADS_API_KEY ?? '';
    const endpoint = options.endpoint ?? process.env.PPT_LEADS_ENDPOINT ?? DEFAULT_ENDPOINT;
    const siteUrl = siteUrlFrom(options, header);

    if (!apiKey) {
      log('warn', 'PPT_LEADS_API_KEY is not set, so this lead was not sent to the dashboard.');
      return { ok: false, skipped: true, queued: false, leadId: '', error: 'Leads API not configured', cookie: null };
    }

    const contact = extractContact(normaliseFields(fields), options.fieldMap);

    // A submission with neither an email nor a phone number is not a lead.
    if (!contact.email && !contact.phone) {
      log('info', `Skipped form ${options.formId ?? ''}: no email or phone found.`);
      return { ok: false, skipped: true, queued: false, leadId: '', error: 'No email or phone', cookie: null };
    }

    const payload = buildPayload({
      contact,
      attribution: forLead(parseCookies(header('cookie'))),
      siteUrl,
      pageUrl: submittingPageUrl(siteUrl, header('referer')),
      ip: clientIp(header, request),
      userAgent: header('user-agent'),
      hashSalt: options.hashSalt ?? process.env.PPT_HASH_SALT ?? apiKey,
      formId: options.formId,
      formTitle: options.formTitle,
      formPlugin: options.formPlugin ?? 'node',
      isSpam: options.isSpam,
      spamScore: options.spamScore,
      test: options.test,
    });

    const sendOptions = { apiKey, endpoint, siteUrl, timeoutMs: options.timeoutMs ?? 5000, fetch: options.fetch };
    const result = await sendLead(payload, sendOptions);

    if (result.ok) {
      return { ok: true, skipped: false, queued: false, leadId: result.leadId, error: '', cookie: leadCookie(result.leadId, siteUrl.startsWith('https:')) };
    }

    log('warn', `Lead send failed: ${result.error}`);

    if (result.retry) {
      const delays = options.retryDelays ?? RETRY_DELAYS;
      const job = retryInBackground(payload, sendOptions, delays, options.onFailure, log);
      const waitUntil = options.waitUntil ?? vercelWaitUntil;
      try {
        waitUntil(job);
      } catch {
        // Not on Vercel and no waitUntil given: the promise still runs on a
        // long-lived server.
      }
      return { ok: false, skipped: false, queued: true, leadId: '', error: result.error, cookie: null, retrying: job };
    }

    if (options.onFailure) await Promise.resolve(options.onFailure(payload, result)).catch(() => {});
    return { ok: false, skipped: false, queued: false, leadId: '', error: result.error, cookie: null };
  } catch (e) {
    log('error', `Lead capture failed: ${e?.message || e}`);
    return { ok: false, skipped: false, queued: false, leadId: '', error: String(e?.message || e), cookie: null };
  }
}

function makeLogger(options) {
  if (options.logger === false) return () => {};
  const logger = options.logger ?? console;
  return (level, message) => {
    if (level === 'info' && !options.debug) return;
    (logger[level] ?? logger.log)?.call(logger, `[ppt] ${message}`);
  };
}
