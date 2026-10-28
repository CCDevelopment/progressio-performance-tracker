// The browser half: the shipped tracker is the plugin's, the config matches
// the WordPress defaults, and window.ppt.formSubmitted() drives generate_lead.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { webcrypto } from 'node:crypto';
import { trackerConfig, trackerHead, trackerScripts } from '../src/head.js';
import { VERSION } from '../src/version.js';

const pluginTracker = readFileSync(new URL('../../../assets/tracker.js', import.meta.url), 'utf8');

test('dist/tracker.js is the plugin tracker, byte for byte', () => {
  assert.equal(readFileSync(new URL('../dist/tracker.js', import.meta.url), 'utf8'), pluginTracker);
});

test('package.json version matches VERSION', () => {
  const pkg = JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8'));
  assert.equal(pkg.version, VERSION);
});

test('config defaults match the WordPress settings defaults', () => {
  const c = trackerConfig({ measurementId: 'G-ABC123', siteUrl: 'https://www.the-socialscene.com' });
  assert.equal(c.siteDomain, 'the-socialscene.com');
  assert.equal(c.formPlugin, 'manual');
  assert.equal(c.attributionDays, 90);
  assert.equal(c.leadsEnabled, true);
  assert.equal(c.trackForms && c.trackScroll && c.trackOutbound && c.trackPhone && c.trackEmail && c.trackDownloads && c.trackVideo, true);
  assert.equal(c.ctaClasses[0].cssClass, 'btn--action');
  assert.equal(trackerConfig({ measurementId: 'UA-123-1' }).measurementId, '', 'invalid measurement id dropped');
});

test('gtag.js only with a valid id, and never when the site loads its own', () => {
  assert.equal(trackerScripts({ measurementId: 'G-ABC123' }).length, 4);
  assert.equal(trackerScripts({ measurementId: 'G-ABC123', loadGtag: false }).length, 2);
  assert.equal(trackerScripts({}).length, 2);
});

test('inline JSON cannot close the script tag', () => {
  const html = trackerHead({ ctaClasses: [{ cssClass: 'x', label: '</script><script>alert(1)', tier: 'primary' }] });
  assert.equal(html.match(/<\/script>/g).length, html.match(/<script/g).length);
});

function run({ cookies = {}, formPlugin = 'manual', referrer = '' } = {}) {
  const jar = { ...cookies };
  const events = [];
  const listeners = {};
  const loc = new URL('https://the-socialscene.com/book/');
  const document = {
    referrer,
    readyState: 'complete',
    get cookie() {
      return Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; ');
    },
    set cookie(str) {
      const [pair, ...attrs] = str.split(';').map((s) => s.trim());
      const [k, v] = pair.split('=');
      const maxAge = attrs.find((a) => /^Max-Age=/i.test(a));
      if (maxAge && parseInt(maxAge.split('=')[1], 10) < 0) delete jar[k];
      else jar[k] = v;
    },
    addEventListener(n, cb) {
      (listeners[n] ||= []).push(cb);
    },
    querySelector: () => null,
    querySelectorAll: () => [],
    documentElement: { scrollHeight: 2000 },
  };
  const config = trackerConfig({ formPlugin });
  config.siteDomain = '';
  const window = {
    pptConfig: config,
    location: loc,
    document,
    addEventListener() {},
    crypto: webcrypto,
    gtag: (...args) => events.push(args),
  };
  window.window = window;
  const ctx = vm.createContext({ window, document, location: loc, URL, URLSearchParams, console, requestAnimationFrame: (f) => f(), Date, JSON, Object, Array, String, Math, RegExp, Uint8Array, WeakSet, MutationObserver: undefined, fetch: undefined, jQuery: undefined });
  vm.runInContext(pluginTracker, ctx);
  return { jar, events, window, listeners };
}

test('formSubmitted fires form_submit and, with the lead cookie, generate_lead', () => {
  const env = run({ cookies: { ppt_last_lead: 'lead_42' } });
  // The page-load check fires for a cookie left by a full-page submit; clear
  // that case first so this test is about the fetch() flow.
  env.events.length = 0;
  env.jar.ppt_last_lead = 'lead_43';
  env.window.ppt.formSubmitted('booking', 'Book a food cart', 'node');
  const names = env.events.map((e) => e[1]);
  assert.deepEqual(names, ['form_submit', 'generate_lead']);
  assert.equal(env.events[1][2].lead_id, 'lead_43');
  assert.equal(env.events[1][2].form_id, 'booking');
  assert.equal(env.jar.ppt_last_lead, undefined, 'cookie consumed');
});

test('formSubmitted without a lead cookie fires only form_submit', () => {
  const env = run();
  env.window.ppt.formSubmitted('cart', 'Join the network');
  assert.deepEqual(env.events.map((e) => e[1]), ['form_submit']);
  assert.equal(env.events[0][2].form_plugin, 'custom');
});

test('generate_lead still fires when another listener already sent form_submit', () => {
  const env = run({ formPlugin: 'auto' });
  env.listeners.submit?.forEach((cb) => cb({ target: { matches: () => false, id: 'f1', getAttribute: () => null } }));
  env.jar.ppt_last_lead = 'lead_7';
  env.window.ppt.formSubmitted('f1', 'Form');
  assert.deepEqual(env.events.map((e) => e[1]), ['form_submit', 'generate_lead']);
});

test('site domain falls back to the page host, so internal navigation is not a referral', () => {
  const env = run({ referrer: 'https://www.the-socialscene.com/' });
  const lt = JSON.parse(decodeURIComponent(env.jar.ppt_lt));
  assert.equal(lt.ch, 'Direct');
});
