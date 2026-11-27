// The browser half: what PPT_Tracker::maybe_output_gtag_snippet() and
// enqueue_tracker() output on a WordPress site, as tags for any framework.
//
// tracker.js itself is not copied by hand: scripts/sync-tracker.mjs builds
// dist/tracker-source.js from the plugin's assets/tracker.js, so a Node site and a
// WordPress site on the same version run byte-for-byte the same tracker.

import TRACKER_SOURCE from '../dist/tracker-source.js';
import { stripWww } from './attribution.js';

const DEFAULT_DOWNLOAD_EXTENSIONS = [
  'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'rtf',
  'zip', 'rar', '7z', 'gz', 'tar',
  'mp3', 'wav', 'm4a', 'mp4', 'mov', 'avi', 'wmv', 'webm',
  'dmg', 'exe', 'pkg', 'apk', 'msi',
  'svg', 'ai', 'psd', 'eps',
];

const DEFAULT_CTA = [
  { cssClass: 'btn--action', label: 'Primary CTA', tier: 'primary' },
  { cssClass: 'btn--secondary', label: 'Secondary CTA', tier: 'secondary' },
  { cssClass: 'btn--tertiary', label: 'Tertiary CTA', tier: 'tertiary' },
];

const isMeasurementId = (id) => /^G-[A-Z0-9]+$/.test(String(id || ''));

/** JSON safe to place inside a <script> element. */
function scriptJson(value) {
  return JSON.stringify(value)
    .replace(/</g, '\\u003c')
    .replace(new RegExp('\\u2028', 'g'), '\\u2028')
    .replace(new RegExp('\\u2029', 'g'), '\\u2029');
}

/**
 * The window.pptConfig object, with the same keys and defaults as the
 * WordPress settings page.
 *
 * @param {import('../index').TrackerOptions} [options]
 */
export function trackerConfig(options = {}) {
  const siteUrl = options.siteUrl ?? process.env.PPT_SITE_URL ?? '';
  let siteDomain = '';
  try {
    siteDomain = siteUrl ? stripWww(new URL(siteUrl).hostname) : '';
  } catch {
    siteDomain = '';
  }
  const flag = (v, d = true) => (v === undefined ? d : Boolean(v));

  return {
    measurementId: isMeasurementId(options.measurementId) ? options.measurementId : '',
    debugMode: flag(options.debug, false),
    ctaClasses: options.ctaClasses ?? DEFAULT_CTA,
    trackForms: flag(options.trackForms),
    // "manual": forms report success through window.ppt.formSubmitted(), so
    // form_submit fires only for submissions the server accepted, as it does
    // with the WordPress form plugins' success events.
    formPlugin: options.formPlugin ?? 'manual',
    trackScroll: flag(options.trackScroll),
    trackOutbound: flag(options.trackOutbound),
    trackPhone: flag(options.trackPhone),
    trackEmail: flag(options.trackEmail),
    trackDownloads: flag(options.trackDownloads),
    trackVideo: flag(options.trackVideo),
    downloadExtensions: options.downloadExtensions ?? DEFAULT_DOWNLOAD_EXTENSIONS,
    // Empty is fine: tracker.js falls back to location.hostname.
    siteDomain,
    attributionDays: Math.max(1, Math.min(365, parseInt(options.attributionDays ?? 90, 10) || 90)),
    leadsEnabled: flag(options.leadsEnabled),
  };
}

/**
 * The script tags, in order, as data: { src } for an external script,
 * { code } for an inline one. Frameworks render these however they prefer;
 * trackerHead() turns them into an HTML string.
 *
 * gtag.js is only added when loadGtag is not false and measurementId is a
 * valid G- id. Pass loadGtag: false on sites that already load gtag.js.
 *
 * @param {import('../index').TrackerOptions} [options]
 * @returns {Array<{ src: string } | { code: string }>}
 */
export function trackerScripts(options = {}) {
  const config = trackerConfig(options);
  const scripts = [];

  if (options.loadGtag !== false && config.measurementId) {
    const gtagConfig = config.debugMode ? { debug_mode: true } : {};
    scripts.push({ src: `https://www.googletagmanager.com/gtag/js?id=${config.measurementId}` });
    scripts.push({
      code:
        'window.dataLayer = window.dataLayer || [];\n' +
        'function gtag(){dataLayer.push(arguments);}\n' +
        "gtag('js', new Date());\n" +
        `gtag('config', ${scriptJson(config.measurementId)}, ${scriptJson(gtagConfig)});`,
    });
  }

  scripts.push({ code: `window.pptConfig = ${scriptJson(config)};` });
  scripts.push({ code: TRACKER_SOURCE.replace(/<\/script/gi, '<\\/script') });
  return scripts;
}

/**
 * The tracker as an HTML string for <head> or the end of <body>.
 *
 * Astro:  <Fragment set:html={trackerHead({ measurementId: 'G-XXXX' })} />
 *
 * @param {import('../index').TrackerOptions} [options]
 */
export function trackerHead(options = {}) {
  return trackerScripts(options)
    .map((s) => ('src' in s ? `<script async src="${s.src.replace(/"/g, '&quot;')}"></script>` : `<script>${s.code}</script>`))
    .join('\n');
}
