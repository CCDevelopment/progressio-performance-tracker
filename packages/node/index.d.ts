export declare const VERSION: string;

export type FormFields =
  | Record<string, unknown>
  | Array<{ id: string; label?: string; type?: string; value: unknown }>;

export interface CaptureOptions {
  /** Shown in the dashboard's Leads Inbox. */
  formId?: string;
  formTitle?: string;
  /** Defaults to "node". */
  formPlugin?: string;
  /** Explicit field names per role, e.g. { name: 'first_name|last_name' }. Heuristics fill the rest. */
  fieldMap?: Partial<Record<'name' | 'email' | 'phone' | 'message', string>>;
  isSpam?: boolean;
  spamScore?: number;
  spamReason?: string;
  spamRule?: string;
  /**
   * Run the Progressio spam filter (global block list from the dashboard plus
   * spamAdd / spamAllow). Defaults to process.env.PPT_SPAM_FILTER. A match
   * sets isSpam, spamReason and spamRule on the lead and `spam` on the result.
   */
  spamFilter?: boolean;
  /** Extra rules for this site, one per line (or an array). Env: PPT_SPAM_ADD, "|"-separated. */
  spamAdd?: string | string[];
  /** Global rules to ignore on this site, one per line (or an array). Env: PPT_SPAM_ALLOW. */
  spamAllow?: string | string[];
  /** Use this rule set instead of fetching the global list. */
  spamRules?: SpamRules;
  /** Defaults to the leads endpoint with /leads replaced by /spam-rules. Env: PPT_SPAM_RULES_ENDPOINT. */
  spamRulesEndpoint?: string;
  /** Timeout for fetching the global list (once per instance, then cached). Default 3000. */
  spamTimeoutMs?: number;
  /** Set false if the site delivers flagged submissions anyway; the lead is then flagged but not marked blocked. */
  spamBlocks?: boolean;
  /** Stored, but hidden from the client's inbox, the form-fill count and GA4. */
  test?: boolean;
  /** Defaults to process.env.PPT_LEADS_API_KEY. */
  apiKey?: string;
  /** Defaults to process.env.PPT_LEADS_ENDPOINT, then the dashboard. */
  endpoint?: string;
  /** Defaults to process.env.PPT_SITE_URL, then the request's host. */
  siteUrl?: string;
  /** Salt for ipHash. Defaults to process.env.PPT_HASH_SALT, then the API key. */
  hashSalt?: string;
  /** Inline attempt timeout. Default 5000. */
  timeoutMs?: number;
  /** Background retry delays after a transient failure. Default [2000, 8000, 20000]. */
  retryDelays?: number[];
  /** Keeps the function alive for background retries. Defaults to @vercel/functions waitUntil. */
  waitUntil?: (promise: Promise<unknown>) => void;
  /** Called with a lead that could not be delivered after every retry. */
  onFailure?: (payload: Record<string, unknown>, result: SendResult | undefined) => void | Promise<void>;
  /** Log info-level messages too. */
  debug?: boolean;
  /** console by default; false silences it. */
  logger?: Pick<Console, 'info' | 'warn' | 'error'> | false;
  /** For tests. */
  fetch?: typeof fetch;
}

export interface LeadCookie {
  name: 'ppt_last_lead';
  value: string;
  options: { path: '/'; maxAge: number; sameSite: 'lax'; httpOnly: false; secure: boolean };
  /** Ready-made Set-Cookie header value. */
  header: string;
}

export interface CaptureResult {
  /** The dashboard stored the lead. */
  ok: boolean;
  /** Not sent: no API key, or the form had no email or phone. */
  skipped: boolean;
  /** The first attempt failed transiently; retries continue in the background. */
  queued: boolean;
  leadId: string;
  error: string;
  /** Set this on the form's response so tracker.js fires generate_lead. Null unless ok, and null for spam. */
  cookie: LeadCookie | null;
  /** The rule the submission tripped, or null. Present even when skipped. */
  spam: SpamHit | null;
  /** True when `spam` is set: reject the form instead of emailing the client. */
  blocked: boolean;
}

export interface SpamRules {
  phrases?: string[];
  domains?: string[];
  emails?: string[];
  phones?: string[];
}

export interface SpamHit {
  type: 'phrases' | 'domains' | 'emails' | 'phones';
  rule: string;
  /** Label (or id) of the field that matched. */
  field: string;
}

export interface SendResult {
  ok: boolean;
  code: number;
  error: string;
  leadId: string;
  retry: boolean;
}

export declare function captureLead(request: unknown, fields: FormFields, options?: CaptureOptions): Promise<CaptureResult>;

/** Spam verdict only, with the same rules captureLead() uses. Never throws. */
export declare function checkSpam(
  fields: FormFields,
  options?: Pick<CaptureOptions, 'apiKey' | 'endpoint' | 'spamAdd' | 'spamAllow' | 'spamRules' | 'spamRulesEndpoint' | 'spamTimeoutMs' | 'fetch'>,
): Promise<SpamHit | null>;

export declare function evaluateSpam(
  fields: Array<{ id: string; label: string; type: string; value: string }>,
  rules: SpamRules,
): SpamHit | null;
export declare function matchValue(value: string, rules: SpamRules): { type: SpamHit['type']; rule: string } | null;
export declare function parseRuleLines(text: string | string[]): Required<SpamRules>;
export declare function effectiveRules(input: { global?: SpamRules | Record<string, unknown>; add?: string | string[] | SpamRules; allow?: string | string[] | SpamRules }): Required<SpamRules>;
export declare function getSpamRules(options?: {
  apiKey?: string;
  endpoint?: string;
  fetch?: typeof fetch;
  timeoutMs?: number;
}): Promise<{ rules: Required<SpamRules>; fetchedAt: number; error: string }>;

/** Adds the lead cookie to a Node ServerResponse, a Headers object or a Web Response. */
export declare function applyLeadCookie(target: unknown, result: CaptureResult): void;

export declare function buildPayload(input: Record<string, unknown>): Record<string, unknown>;
export declare function sendLead(
  payload: Record<string, unknown>,
  options?: { apiKey?: string; endpoint?: string; siteUrl?: string; timeoutMs?: number; fetch?: typeof fetch },
): Promise<SendResult>;

export interface TrackerOptions {
  /** GA4 measurement id, G-XXXXXXX. */
  measurementId?: string;
  /** Default true. False on sites that already load gtag.js themselves. */
  loadGtag?: boolean;
  /** Defaults to process.env.PPT_SITE_URL; tracker.js falls back to the page's own host. */
  siteUrl?: string;
  debug?: boolean;
  ctaClasses?: Array<{ cssClass: string; label: string; tier: string }>;
  trackForms?: boolean;
  /** Default "manual": report successes with window.ppt.formSubmitted(). */
  formPlugin?: string;
  trackScroll?: boolean;
  trackOutbound?: boolean;
  trackPhone?: boolean;
  trackEmail?: boolean;
  trackDownloads?: boolean;
  trackVideo?: boolean;
  downloadExtensions?: string[];
  attributionDays?: number;
  /** Default true: fire generate_lead when the server accepted a lead. */
  leadsEnabled?: boolean;
}

export declare function trackerConfig(options?: TrackerOptions): Record<string, unknown>;
export declare function trackerScripts(options?: TrackerOptions): Array<{ src: string } | { code: string }>;
export declare function trackerHead(options?: TrackerOptions): string;

export declare function normaliseFields(input: FormFields): Array<{ id: string; label: string; type: string; value: string }>;
export declare function extractContact(
  fields: Array<{ id: string; label: string; type: string; value: string }>,
  map?: CaptureOptions['fieldMap'],
): Partial<Record<'name' | 'email' | 'phone' | 'message', string>>;
export declare function classify(touch: Record<string, unknown>): string;

declare global {
  interface Window {
    ppt?: { formSubmitted(formId: string, formTitle?: string, plugin?: string): void };
  }
}
