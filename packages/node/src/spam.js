// Port of PPT_Spam (includes/class-ppt-spam.php): the same rule parsing,
// normalisation and matching, so a submission blocked on a WordPress site is
// blocked the same way on a Node site.
//
// Rules are literal strings of four kinds: phrases, domains, emails, phones.
// The global list comes from the dashboard (GET …/api/spam-rules, Bearer key)
// and is cached in memory per function instance; a site adds or exempts
// entries through options or env. Nothing here throws.

import { isEmail } from './sanitize.js';
import { DEFAULT_ENDPOINT, VERSION } from './version.js';

export const RULE_TYPES = ['phrases', 'domains', 'emails', 'phones'];

const MAX_RULES_PER_TYPE = 1000;
const MAX_RULE_LENGTH = 200;
const FRESH_FOR_MS = 6 * 60 * 60 * 1000;
const RETRY_AFTER_MS = 10 * 60 * 1000;

const stripTags = (s) => String(s).replace(/<[^>]*>/g, '');

export function emptyRules() {
  return Object.fromEntries(RULE_TYPES.map((t) => [t, []]));
}

/** PPT_Spam::normalize(): one rule of a known type, or '' when unusable. */
export function normalizeRule(type, rule) {
  let r = stripTags(rule).replace(/\s+/g, ' ').trim();
  if (r === '' || r.length > MAX_RULE_LENGTH) return '';
  switch (type) {
    case 'phones': {
      let digits = r.replace(/\D+/g, '');
      if (digits.length === 11 && digits[0] === '1') digits = digits.slice(1);
      return digits.length >= 7 && digits.length <= 15 ? digits : '';
    }
    case 'domains': {
      r = r.toLowerCase().replace(/^[a-z]+:\/\//, '').split('/')[0].replace(/^www\./, '');
      return /^(?:[a-z0-9-]+\.)+[a-z]{2,}$/.test(r) ? r : '';
    }
    case 'emails':
      r = r.toLowerCase();
      return isEmail(r) ? r : '';
    default:
      return r.toLowerCase();
  }
}

/** PPT_Spam::classify_line(): [type, rule], type '' when the line is not a rule. */
export function classifyLine(line) {
  const l = String(line ?? '').trim();
  if (l === '' || l[0] === '#') return ['', ''];
  const m = l.match(/^(phrase|domain|email|phone)s?\s*:\s*(.+)$/i);
  if (m) {
    const type = `${m[1].toLowerCase()}s`;
    return [type, normalizeRule(type, m[2])];
  }
  if (l.includes('@') && isEmail(l.toLowerCase())) return ['emails', normalizeRule('emails', l)];
  if (!l.includes(' ') && normalizeRule('domains', l) !== '') return ['domains', normalizeRule('domains', l)];
  if (/^\+?[\d\s().-]{7,}$/.test(l) && normalizeRule('phones', l) !== '') return ['phones', normalizeRule('phones', l)];
  return ['phrases', normalizeRule('phrases', l)];
}

function tidy(rules) {
  const out = emptyRules();
  for (const type of RULE_TYPES) {
    const list = (rules?.[type] ?? []).map(String).filter((r) => r !== '');
    out[type] = [...new Set(list)].slice(0, MAX_RULES_PER_TYPE);
  }
  return out;
}

/**
 * PPT_Spam::parse_lines(): a one-rule-per-line list (string, or an array of
 * lines) into a typed rule set. "|" also separates rules, for env variables.
 */
export function parseRuleLines(text) {
  const lines = Array.isArray(text) ? text : String(text ?? '').split(/\r\n|\r|\n|\|/);
  const rules = emptyRules();
  for (const line of lines) {
    const [type, rule] = classifyLine(line);
    if (type && rule) rules[type].push(rule);
  }
  return tidy(rules);
}

/** PPT_Spam::from_array(): a dashboard response (or any loose object) into a rule set. */
export function rulesFromObject(data) {
  const rules = emptyRules();
  if (!data || typeof data !== 'object') return rules;
  for (const wrap of ['data', 'rules']) {
    if (data[wrap] && typeof data[wrap] === 'object') data = data[wrap];
  }
  const aliases = {
    phrases: ['phrases', 'phrase', 'keywords', 'keyword'],
    domains: ['domains', 'domain'],
    emails: ['emails', 'email'],
    phones: ['phones', 'phone', 'phone_numbers', 'phoneNumbers'],
  };
  for (const [type, keys] of Object.entries(aliases)) {
    for (const key of keys) {
      if (data[key] === undefined || data[key] === null) continue;
      let list = data[key];
      if (typeof list === 'string') list = list.split(/\r\n|\r|\n|,/);
      for (let item of [].concat(list)) {
        if (item && typeof item === 'object') item = item.value ?? item.rule ?? '';
        if (item !== null && item !== undefined && typeof item !== 'object') rules[type].push(normalizeRule(type, String(item)));
      }
    }
  }
  return tidy(rules);
}

export function countRules(rules) {
  return RULE_TYPES.reduce((n, t) => n + (rules?.[t]?.length ?? 0), 0);
}

/** PPT_Spam::effective_rules(): global + additions - exceptions. */
export function effectiveRules({ global, add, allow } = {}) {
  const g = rulesFromObject(global);
  const a = Array.isArray(add) || typeof add === 'string' ? parseRuleLines(add) : rulesFromObject(add);
  const x = Array.isArray(allow) || typeof allow === 'string' ? parseRuleLines(allow) : rulesFromObject(allow);
  const out = emptyRules();
  for (const type of RULE_TYPES) {
    const drop = new Set(x[type]);
    out[type] = [...new Set([...g[type], ...a[type]])].filter((r) => !drop.has(r));
  }
  return out;
}

// ── Matching ─────────────────────────────────────────────────────────────────

const escapeRe = (s) => s.replace(/[.*+?^${}()|[\]\\\/-]/g, '\\$&');

function phraseRegex(phrase) {
  const body = phrase.split(/[\s-]+/).map(escapeRe).join('[\\s\\-]+');
  return new RegExp(`(?<![\\p{L}\\p{N}])${body}(?![\\p{L}\\p{N}])`, 'iu');
}

function decodeEntities(s) {
  return String(s)
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#0*39;|&apos;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

/** PPT_Spam::phone_candidates(): every phone-shaped digit run, normalised. */
export function phoneCandidates(value) {
  const out = new Set();
  for (const run of String(value).match(/\+?\d(?:[\d\s().\-]*\d)?/g) ?? []) {
    const digits = normalizeRule('phones', run);
    if (digits) out.add(digits);
  }
  return [...out];
}

/**
 * PPT_Spam::match_value(): the first rule a string trips.
 * @returns {{ type: string, rule: string } | null}
 */
export function matchValue(value, rules) {
  const v = decodeEntities(value);
  for (const phrase of rules?.phrases ?? []) {
    if (phrase && phraseRegex(phrase).test(v)) return { type: 'phrases', rule: phrase };
  }
  for (const email of rules?.emails ?? []) {
    if (email && new RegExp(`(?<![a-z0-9._%+-])${escapeRe(email)}(?![a-z0-9-])`, 'i').test(v)) return { type: 'emails', rule: email };
  }
  for (const domain of rules?.domains ?? []) {
    if (domain && new RegExp(`(?<![a-z0-9-])(?:[a-z0-9-]+\\.)*${escapeRe(domain)}(?![a-z0-9-])`, 'i').test(v)) return { type: 'domains', rule: domain };
  }
  if (rules?.phones?.length) {
    const set = new Set(rules.phones);
    for (const digits of phoneCandidates(v)) {
      if (set.has(digits)) return { type: 'phones', rule: digits };
    }
  }
  return null;
}

/**
 * PPT_Spam::evaluate(): test normalised fields against a rule set.
 * @param {Array<{id: string, label: string, type: string, value: string}>} fields
 * @returns {{ type: string, rule: string, field: string } | null}
 */
export function evaluateSpam(fields, rules) {
  if (!countRules(rules)) return null;
  for (const f of fields ?? []) {
    const value = f && typeof f === 'object' ? String(f.value ?? '') : String(f ?? '');
    if (value.trim() === '') continue;
    const hit = matchValue(value, rules);
    if (hit) return { ...hit, field: f && typeof f === 'object' ? f.label || f.id || '' : '' };
  }
  return null;
}

// ── Global list ──────────────────────────────────────────────────────────────

/** …/api/leads → …/api/spam-rules, as PPT_Spam::rules_endpoint() does. */
export function spamRulesEndpoint(leadsEndpoint) {
  const leads = String(leadsEndpoint || DEFAULT_ENDPOINT);
  // Already a rules URL (a spamRulesEndpoint / PPT_SPAM_RULES_ENDPOINT override): use it as is.
  if (/\/spam-rules\/?$/.test(leads)) return leads;
  return /\/leads\/?$/.test(leads) ? leads.replace(/\/leads\/?$/, '/spam-rules') : `${leads.replace(/\/+$/, '')}/spam-rules`;
}

/** Per function instance: endpoint+key → { rules, fetchedAt, erroredAt, error, pending }. */
const cache = new Map();

/** For tests. */
export function resetSpamRulesCache() {
  cache.clear();
}

/**
 * The cached global list, refreshed when older than six hours. A failed fetch
 * keeps the last good copy (or an empty set) and is not retried for ten
 * minutes, so a dashboard outage costs one short timeout, not one per lead.
 *
 * @returns {Promise<{ rules: object, fetchedAt: number, error: string }>}
 */
export async function getSpamRules({ apiKey, endpoint, fetch: fetchImpl = globalThis.fetch, timeoutMs = 3000, now = Date.now } = {}) {
  const url = spamRulesEndpoint(endpoint);
  if (!apiKey) return { rules: emptyRules(), fetchedAt: 0, error: 'Leads API not configured' };

  const key = `${url}\u0000${apiKey}`;
  const entry = cache.get(key) ?? { rules: emptyRules(), fetchedAt: 0, erroredAt: 0, error: '', pending: null };
  cache.set(key, entry);

  const t = now();
  const fresh = entry.fetchedAt && t - entry.fetchedAt < FRESH_FOR_MS;
  const cooling = entry.erroredAt && t - entry.erroredAt < RETRY_AFTER_MS;
  if (fresh || cooling) return { rules: entry.rules, fetchedAt: entry.fetchedAt, error: entry.error };

  if (!entry.pending) {
    entry.pending = (async () => {
      try {
        const res = await fetchImpl(url, {
          method: 'GET',
          headers: { Accept: 'application/json', Authorization: `Bearer ${apiKey}`, 'X-PPT-Version': VERSION },
          signal: AbortSignal.timeout(timeoutMs),
        });
        const body = await res.text().catch(() => '');
        if (res.status < 200 || res.status >= 300) throw new Error(`HTTP ${res.status}: ${body.slice(0, 200)}`);
        entry.rules = rulesFromObject(JSON.parse(body));
        entry.fetchedAt = now();
        entry.error = '';
        entry.erroredAt = 0;
      } catch (e) {
        entry.error = e?.name === 'TimeoutError' ? 'Timed out' : String(e?.message || e);
        entry.erroredAt = now();
      } finally {
        entry.pending = null;
      }
    })();
  }
  await entry.pending;
  return { rules: entry.rules, fetchedAt: entry.fetchedAt, error: entry.error };
}

const truthy = (v) => v !== undefined && v !== null && v !== '' && !['0', 'false', 'off', 'no'].includes(String(v).toLowerCase());

/**
 * Resolve the spam options a site passed (or set in env) into the rules that
 * apply to one submission. Returns null when the filter is off.
 *
 * Options: spamFilter (boolean; env PPT_SPAM_FILTER), spamRules (a complete
 * rule set to use instead of fetching), spamAdd / spamAllow (lines; env
 * PPT_SPAM_ADD / PPT_SPAM_ALLOW, "|"-separated), spamRulesEndpoint.
 */
export async function resolveSpamRules(options = {}, { apiKey, endpoint, fetch: fetchImpl } = {}) {
  const enabled = options.spamFilter ?? truthy(process.env.PPT_SPAM_FILTER);
  if (!enabled) return null;
  const add = options.spamAdd ?? process.env.PPT_SPAM_ADD ?? '';
  const allow = options.spamAllow ?? process.env.PPT_SPAM_ALLOW ?? '';
  let global = options.spamRules;
  let error = '';
  if (!global) {
    const got = await getSpamRules({ apiKey, endpoint: options.spamRulesEndpoint ?? process.env.PPT_SPAM_RULES_ENDPOINT ?? endpoint, fetch: fetchImpl, timeoutMs: options.spamTimeoutMs ?? 3000 });
    global = got.rules;
    error = got.error;
  }
  return { rules: effectiveRules({ global, add, allow }), error };
}
