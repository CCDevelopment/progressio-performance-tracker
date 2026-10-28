// captureLead end to end against a fake Leads API. The payload assertions
// mirror the CF7 dispatch case in tests/smoke/php-harness.php.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { applyLeadCookie, captureLead } from '../src/leads.js';

const cookie = (obj) => encodeURIComponent(JSON.stringify(obj));

const COOKIES =
  `ppt_ft=${cookie({ s: 'google', m: 'cpc', g: 'CjX', lp: '/landing?gclid=CjX', ts: 1700000000, v: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' })}; ` +
  `ppt_lt=${cookie({ lp: '/pricing', ts: 1700090000, ch: 'Direct' })}`;

function request(extra = {}) {
  return new Request('https://www.example.com/api/contact', {
    method: 'POST',
    headers: {
      cookie: COOKIES,
      referer: 'https://www.example.com/contact/',
      'x-forwarded-for': '203.0.113.5, 10.0.0.1',
      'user-agent': 'TestUA',
      ...extra,
    },
  });
}

function fakeApi(...responses) {
  const calls = [];
  const fetch = async (url, init) => {
    calls.push({ url, init, body: JSON.parse(init.body) });
    const next = responses.length > 1 ? responses.shift() : responses[0];
    if (next instanceof Error) throw next;
    return new Response(JSON.stringify(next.body ?? {}), { status: next.status });
  };
  return { fetch, calls };
}

const base = { apiKey: 'test-key-abcdef', siteUrl: 'https://www.example.com', logger: false, waitUntil: () => {} };

test('payload matches the WordPress plugin', async () => {
  const api = fakeApi({ status: 201, body: { ok: true, id: 'lead_123' } });
  const r = await captureLead(request(), { 'your-name': 'Bob', 'your-email': 'bob@example.com', topics: ['a', 'b'] }, {
    ...base,
    formId: '5',
    formTitle: 'Contact',
    formPlugin: 'cf7',
    isSpam: false,
    fetch: api.fetch,
  });

  assert.equal(r.ok, true);
  assert.equal(r.leadId, 'lead_123');
  assert.equal(api.calls.length, 1);

  const { url, init, body: p } = api.calls[0];
  assert.equal(url, 'https://dashboard.progressiodev.com/api/leads');
  assert.equal(init.headers.Authorization, 'Bearer test-key-abcdef');
  assert.equal(init.headers['X-PPT-Version'], '1.6.0');
  assert.equal(p.apiKey, undefined, 'key never sent in the body');
  assert.equal(p.email, 'bob@example.com');
  assert.equal(p.name, 'Bob');
  assert.equal(p.source, 'google');
  assert.equal(p.medium, 'cpc');
  assert.equal(p.channel, 'Paid Search', 'falls back to first touch when the session is direct');
  assert.equal(p.landingPage, 'https://www.example.com/landing?gclid=CjX');
  assert.equal(p.sessionLandingPage, 'https://www.example.com/pricing');
  assert.equal(p.pageUrl, 'https://www.example.com/contact/');
  assert.equal(p.lastTouch.channel, 'Direct');
  assert.equal(p.firstTouch.channel, 'Paid Search');
  assert.equal(p.firstTouchAt, '2023-11-14T22:13:20+00:00');
  assert.equal(p.visitorId, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
  assert.equal(p.contactHash.length, 64);
  assert.equal(p.ipHash.length, 32);
  assert.match(p.submissionId, /^[0-9a-f-]{36}$/);
  assert.equal(p.isSpam, false);
  assert.equal(p.formPlugin, 'cf7');
  assert.equal(p.formTitle, 'Contact');
  assert.equal(p.formId, '5');
  assert.equal(p.pluginVersion, '1.6.0');
  assert.equal(p.siteUrl, 'https://www.example.com');
  assert.equal(p.userAgent, 'TestUA');
  assert.ok(!('phone' in p) && !('message' in p) && !('test' in p), 'empty fields omitted');
});

test('contactHash is computed the same way as PHP', async () => {
  const api = fakeApi({ status: 201, body: { id: 'x' } });
  await captureLead(request(), { email: 'Bob@Example.com', phone: '(503) 555-0142' }, { ...base, fetch: api.fetch });
  const { createHash } = await import('node:crypto');
  assert.equal(api.calls[0].body.contactHash, createHash('sha256').update('bob@example.com|5035550142').digest('hex'));
});

test('success hands back the generate_lead cookie', async () => {
  const api = fakeApi({ status: 201, body: { ok: true, id: 'lead_9' } });
  const r = await captureLead(request(), { email: 'a@example.com' }, { ...base, fetch: api.fetch });
  assert.equal(r.cookie.header, 'ppt_last_lead=lead_9; Path=/; Max-Age=300; SameSite=Lax; Secure');

  // Node ServerResponse (Vercel /api functions)
  const set = [];
  applyLeadCookie({ appendHeader: (k, v) => set.push([k, v]), setHeader() {} }, r);
  assert.deepEqual(set, [['Set-Cookie', r.cookie.header]]);

  // Web Response (Next route handlers, Astro endpoints)
  const res = Response.json({ ok: true });
  applyLeadCookie(res, r);
  assert.equal(res.headers.get('set-cookie'), r.cookie.header);
});

test('a transient failure is retried in the background until it lands', async () => {
  const api = fakeApi({ status: 503 }, { status: 503 }, { status: 201, body: { id: 'late' } });
  let kept;
  const r = await captureLead(request(), { email: 'a@example.com' }, { ...base, fetch: api.fetch, retryDelays: [0, 0, 0], waitUntil: (p) => (kept = p) });
  assert.equal(r.ok, false);
  assert.equal(r.queued, true);
  assert.equal(r.cookie, null, 'no generate_lead for a lead that has not landed');
  assert.ok(kept, 'background work handed to waitUntil');
  await kept;
  assert.equal(api.calls.length, 3);
  assert.equal(api.calls[0].body.submissionId, api.calls[2].body.submissionId, 'same submissionId, so the dashboard dedupes');
});

test('a network error is retried; onFailure gets the lead when every retry fails', async () => {
  const api = fakeApi(new TypeError('fetch failed'));
  let failed;
  const r = await captureLead(request(), { email: 'a@example.com' }, {
    ...base,
    fetch: api.fetch,
    retryDelays: [0, 0],
    waitUntil: () => {},
    onFailure: (payload) => (failed = payload),
  });
  await r.retrying;
  assert.equal(api.calls.length, 3);
  assert.equal(failed.email, 'a@example.com');
});

test('4xx is not retried', async () => {
  const api = fakeApi({ status: 400, body: { error: 'At least one of name, email, or phone is required' } });
  const r = await captureLead(request(), { email: 'a@example.com' }, { ...base, fetch: api.fetch, retryDelays: [0] });
  assert.equal(r.queued, false);
  assert.match(r.error, /^HTTP 400/);
  assert.equal(api.calls.length, 1);
});

test('no email or phone: not a lead, nothing sent', async () => {
  const api = fakeApi({ status: 201 });
  const r = await captureLead(request(), { first_name: 'Nobody', message: 'hi' }, { ...base, fetch: api.fetch });
  assert.equal(r.skipped, true);
  assert.equal(api.calls.length, 0);
});

test('no API key: skipped, never throws', async () => {
  const before = process.env.PPT_LEADS_API_KEY;
  delete process.env.PPT_LEADS_API_KEY;
  const r = await captureLead(request(), { email: 'a@example.com' }, { logger: false });
  assert.equal(r.skipped, true);
  if (before !== undefined) process.env.PPT_LEADS_API_KEY = before;
});

test('Node IncomingMessage-style headers work too', async () => {
  const api = fakeApi({ status: 201, body: { id: 'n1' } });
  const req = { headers: { cookie: COOKIES, referer: 'https://www.example.com/book/', host: 'www.example.com', 'x-forwarded-proto': 'https' }, socket: { remoteAddress: '198.51.100.7' } };
  const r = await captureLead(req, { email: 'a@example.com' }, { apiKey: 'k', logger: false, fetch: api.fetch });
  assert.equal(r.ok, true);
  assert.equal(api.calls[0].body.siteUrl, 'https://www.example.com', 'site URL from the Host header');
  assert.equal(api.calls[0].body.pageUrl, 'https://www.example.com/book/');
  assert.equal(api.calls[0].body.ipHash.length, 32);
});

test('test leads are flagged', async () => {
  const api = fakeApi({ status: 201, body: { id: 't' } });
  await captureLead(request(), { email: 'me@example.com' }, { ...base, fetch: api.fetch, test: true });
  assert.equal(api.calls[0].body.test, true);
});
