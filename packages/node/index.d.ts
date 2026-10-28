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
  /** Set this on the form's response so tracker.js fires generate_lead. Null unless ok. */
  cookie: LeadCookie | null;
}

export interface SendResult {
  ok: boolean;
  code: number;
  error: string;
  leadId: string;
  retry: boolean;
}

export declare function captureLead(request: unknown, fields: FormFields, options?: CaptureOptions): Promise<CaptureResult>;

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
