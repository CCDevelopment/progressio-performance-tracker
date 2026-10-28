// Port of includes/class-ppt-attribution.php. Reads the first-touch / last-touch
// cookies written by tracker.js, sanitizes them and classifies the channel.
//
// The cookies are visitor-controlled input: nothing here is trusted until it
// has been coerced to a string, length-capped and sanitized. Keep this file in
// step with the PHP class and with classifyChannel() in assets/tracker.js.

import { sanitizeText } from './sanitize.js';

export const COOKIE_FIRST = 'ppt_ft';
export const COOKIE_LAST = 'ppt_lt';
export const COOKIE_LEAD = 'ppt_last_lead';

const MAX_LEN = 200;

/** Short cookie key → payload/field name. Keep in sync with tracker.js. */
const KEYS = {
  s: 'source',
  m: 'medium',
  c: 'campaign',
  t: 'term',
  n: 'content',
  g: 'gclid',
  f: 'fbclid',
  ms: 'msclkid',
  tt: 'ttclid',
  li: 'li_fat_id',
  dc: 'dclid',
  r: 'referrer',
  lp: 'landing_page',
  ch: 'channel',
  ts: 'timestamp',
  v: 'visitor_id',
};

const CHANNELS = [
  'Paid Search',
  'Paid Social',
  'Display',
  'Organic Search',
  'Organic Social',
  'Email',
  'Affiliate',
  'SMS',
  'Referral',
  'Direct',
];

const CLICK_IDS = ['gclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'dclid'];

/** Parse a Cookie header into { name: rawValue }. First occurrence wins. */
export function parseCookies(header) {
  const out = {};
  for (const part of String(header || '').split(';')) {
    const i = part.indexOf('=');
    if (i < 1) continue;
    const name = part.slice(0, i).trim();
    if (name && !(name in out)) out[name] = part.slice(i + 1).trim();
  }
  return out;
}

function decode(value) {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

/**
 * Read and sanitize one attribution cookie.
 *
 * @param {Record<string,string>} cookies Parsed cookies.
 * @param {string} name COOKIE_FIRST or COOKIE_LAST.
 * @returns {Record<string, string|number>} Sanitized touch keyed by full field name; empty if absent/invalid.
 */
export function readTouch(cookies, name) {
  const raw = cookies[name];
  if (!raw || typeof raw !== 'string' || raw.length > 4096) return {};

  // tracker.js writes encodeURIComponent(JSON). Decode once, and once more if a
  // proxy or framework left it double-encoded.
  let text = decode(raw);
  if (!text.startsWith('{')) text = decode(text);

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    return {};
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) return {};

  return sanitizeTouch(data);
}

/** Coerce a decoded touch object into a clean, string-only structure. */
export function sanitizeTouch(data) {
  const touch = {};

  for (const [short, full] of Object.entries(KEYS)) {
    let value = data[short] ?? data[full] ?? null;
    if (value === null || typeof value === 'object') continue;
    value = String(value).trim();
    if (value === '') continue;

    switch (full) {
      case 'landing_page':
        value = sanitizeSitePath(value);
        break;
      case 'timestamp':
        value = /^\d+$/.test(value) ? parseInt(value, 10) : null;
        break;
      case 'visitor_id':
        value = /^[A-Za-z0-9-]{8,64}$/.test(value) ? value : null;
        break;
      case 'channel':
        value = CHANNELS.includes(value) ? value : null;
        break;
      case 'referrer':
        value = sanitizeText(value.slice(0, MAX_LEN)).toLowerCase();
        value = /^[a-z0-9.-]+$/.test(value) ? value : null;
        break;
      default:
        value = sanitizeText(value.slice(0, MAX_LEN));
    }

    if (value !== null && value !== '') touch[full] = value;
  }

  // Never trust the client's channel label outright — recompute it.
  if (Object.keys(touch).length) touch.channel = classify(touch);

  return touch;
}

/**
 * Classify a touch into a marketing channel. Mirrors PPT_Attribution::classify()
 * and classifyChannel() in tracker.js — keep the three in sync.
 */
export function classify(t) {
  const src = String(t.source || '').toLowerCase();
  const med = String(t.medium || '').toLowerCase();
  const ref = String(t.referrer || '').toLowerCase();

  const searchRe = /(^|\.)(google|bing|yahoo|duckduckgo|baidu|yandex|ecosia|ask|aol|brave|startpage|qwant)\./;
  const socialRe = /(^|\.)(facebook|instagram|linkedin|twitter|x|t|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|tumblr|quora)\.(com|net|co|be|me)$/;
  const socialSrcRe = /^(facebook|fb|ig|instagram|linkedin|twitter|x|tiktok|pinterest|youtube|reddit|threads|snapchat|nextdoor|meta)$/;

  const isSearch = searchRe.test(src + '.') || searchRe.test(ref + '.');
  const isSocial = socialSrcRe.test(src) || socialRe.test(src) || socialRe.test(ref);

  if (t.dclid) return 'Display';
  if (t.gclid || t.msclkid) return isSocial ? 'Paid Social' : 'Paid Search';
  if (t.fbclid || t.ttclid || t.li_fat_id) return 'Paid Social';

  if (['paid_social', 'paidsocial', 'paid-social', 'social_paid', 'social-paid'].includes(med)) return 'Paid Social';
  if (['cpc', 'ppc', 'paidsearch', 'paid_search', 'paid-search', 'paid', 'sem', 'cpv', 'cpa', 'retargeting'].includes(med)) {
    return isSocial ? 'Paid Social' : 'Paid Search';
  }
  if (['display', 'banner', 'cpm', 'expandable', 'interstitial'].includes(med)) return 'Display';
  if (med === 'organic') return 'Organic Search';
  if (['social', 'social-network', 'social-media', 'social_network', 'social_media', 'sm'].includes(med)) return 'Organic Social';
  if (['email', 'e-mail', 'e_mail', 'e mail', 'newsletter'].includes(med)) return 'Email';
  if (med === 'affiliate') return 'Affiliate';
  if (med === 'sms') return 'SMS';

  // No recognised medium — fall back to what the source/referrer looks like.
  if (isSearch) return 'Organic Search';
  if (isSocial) return 'Organic Social';
  if (src !== '' || ref !== '') return 'Referral';
  return 'Direct';
}

/**
 * Keep only the path and a whitelist of query parameters from a URL or path,
 * so sensitive query strings never reach the Leads API.
 */
export function sanitizeSitePath(value) {
  let url;
  try {
    url = new URL(String(value).slice(0, 2000), 'http://placeholder.invalid');
  } catch {
    return null;
  }

  const path = '/' + sanitizeText(url.pathname).replace(/^\/+/, '');

  const keep = new URLSearchParams();
  for (const [k, v] of url.searchParams) {
    if (keep.has(k)) continue;
    if (k.startsWith('utm_') || CLICK_IDS.includes(k)) keep.set(k, sanitizeText(v.slice(0, MAX_LEN)));
  }

  const query = keep.toString();
  return query ? `${path}?${query}` : path;
}

export function stripWww(host) {
  return String(host || '').toLowerCase().replace(/^www\./, '');
}

/** Absolute URL on the site for a sanitized path. Mirrors home_url( $path ). */
export function siteUrlForPath(siteUrl, path) {
  if (!path) return null;
  return String(siteUrl).replace(/\/+$/, '') + '/' + String(path).replace(/^\/+/, '');
}

/**
 * The page the form was submitted from, taken from the Referer header and
 * accepted only when it points at this site.
 */
export function submittingPageUrl(siteUrl, referer) {
  if (!referer || typeof referer !== 'string') return null;
  let refHost;
  let siteHost;
  try {
    refHost = new URL(referer).hostname;
    siteHost = new URL(siteUrl).hostname;
  } catch {
    return null;
  }
  if (!refHost || stripWww(refHost) !== stripWww(siteHost)) return null;
  return siteUrlForPath(siteUrl, sanitizeSitePath(referer));
}

/**
 * Full attribution bundle for a lead payload: last touch plus first touch.
 * A visitor with only a first-touch cookie (new session, no new touch) is a
 * returning direct visit; the last touch is then "Direct".
 */
export function forLead(cookies) {
  const first = readTouch(cookies, COOKIE_FIRST);
  let last = readTouch(cookies, COOKIE_LAST);
  if (!Object.keys(last).length) last = { channel: 'Direct' };
  return { first, last };
}
