// Mirrors the spam cases in tests/smoke/php-harness.php: same rules, same
// verdicts on both platforms.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classifyLine, parseRuleLines, rulesFromObject, effectiveRules, matchValue, evaluateSpam, getSpamRules, resetSpamRulesCache, spamRulesEndpoint } from '../src/spam.js';
import { captureLead, checkSpam } from '../src/leads.js';

test('classifyLine types bare lines the way PHP does', () => {
  assert.deepEqual(classifyLine('  Local   SEO '), ['phrases', 'local seo']);
  assert.deepEqual(classifyLine('https://www.Spammer.example/path'), ['domains', 'spammer.example']);
  assert.deepEqual(classifyLine('Joe@Spammer.example'), ['emails', 'joe@spammer.example']);
  assert.deepEqual(classifyLine('+1 (805) 400-2077'), ['phones', '8054002077']);
  assert.deepEqual(classifyLine('phone: 707.706.0205'), ['phones', '7077060205']);
  assert.deepEqual(classifyLine('phrase: example.com'), ['phrases', 'example.com']);
  assert.deepEqual(classifyLine('# comment'), ['', '']);
  assert.deepEqual(classifyLine(''), ['', '']);
});

test('parseRuleLines dedupes, types, and treats short digit strings as phrases', () => {
  const r = parseRuleLines('seo services\nseo services\nspammer.example\n\n555\nname@x.io');
  assert.deepEqual(r.phrases, ['seo services', '555']);
  assert.deepEqual(r.domains, ['spammer.example']);
  assert.deepEqual(r.emails, ['name@x.io']);
  assert.deepEqual(r.phones, []);
  assert.deepEqual(parseRuleLines('a|b.example').domains, ['b.example'], '"|" separates rules in env vars');
});

test('rulesFromObject tolerates wrappers, aliases, objects and comma lists', () => {
  const r = rulesFromObject({ data: { updatedAt: 'x', keywords: ['Guest Post', { value: 'Backlinks' }], domains: 'a.example, b.example', phones: ['+1 805 400 2077'] } });
  assert.deepEqual(r.phrases, ['guest post', 'backlinks']);
  assert.deepEqual(r.domains, ['a.example', 'b.example']);
  assert.deepEqual(r.phones, ['8054002077']);
});

const R = { phrases: ['aeo', 'no-obligation audit', 'seo services'], domains: ['spammer.example'], emails: ['joe@gmail.example'], phones: ['8054002077'] };

test('matchValue verdicts match the PHP harness', () => {
  assert.equal(matchValue('We run an aeon-long campaign', R), null, 'whole word');
  assert.equal(matchValue('Ask about AEO!', R)?.type, 'phrases');
  assert.equal(matchValue('A free, no obligation   audit', R)?.rule, 'no-obligation audit');
  assert.equal(matchValue('reach me at bob@mail.spammer.example today', R)?.type, 'domains');
  assert.equal(matchValue('see https://spammer.example/offer', R)?.type, 'domains');
  assert.equal(matchValue('notspammer.example is unrelated', R), null);
  assert.equal(matchValue('Joe@Gmail.example', R)?.type, 'emails');
  assert.equal(matchValue('notjoe@gmail.example', R), null);
  assert.equal(matchValue('Call (805) 400-2077 now', R)?.type, 'phones');
  assert.equal(matchValue('+1.805.400.2077', R)?.type, 'phones');
  assert.equal(matchValue('Call 805 400 2078', R), null);
  assert.equal(matchValue('Order #80540020770', R), null);
  assert.equal(matchValue('Hi, I need a quote for my kitchen', R), null);
});

test('effectiveRules = global + additions - exceptions', () => {
  const r = effectiveRules({
    global: { phrases: ['seo services', 'guest post'], phones: ['8054002077'] },
    add: 'cheap backlinks\nlocal.spammer.example',
    allow: 'seo services\n8054002077',
  });
  assert.deepEqual(r.phrases, ['guest post', 'cheap backlinks']);
  assert.deepEqual(r.domains, ['local.spammer.example']);
  assert.deepEqual(r.phones, []);
});

test('evaluateSpam reports the field', () => {
  const hit = evaluateSpam([{ id: 'name', label: 'Name', type: '', value: 'Bob' }, { id: 'msg', label: 'Message', type: 'textarea', value: 'Guest post?' }], { phrases: ['guest post'] });
  assert.deepEqual(hit, { type: 'phrases', rule: 'guest post', field: 'Message' });
  assert.equal(evaluateSpam([{ id: 'x', label: '', type: '', value: 'anything' }], { phrases: [] }), null, 'no rules, no work');
});

test('getSpamRules fetches with the bearer key, caches, and keeps the last copy on failure', async () => {
  resetSpamRulesCache();
  const calls = [];
  let response = () => new Response(JSON.stringify({ updatedAt: 'x', phrases: ['Rank Higher'] }), { status: 200 });
  const fetch = async (url, init) => {
    calls.push({ url, init });
    return response();
  };
  let t = 1_000_000;
  const now = () => t;

  const a = await getSpamRules({ apiKey: 'k', fetch, now });
  assert.equal(calls[0].url, 'https://dashboard.progressiodev.com/api/spam-rules');
  assert.equal(calls[0].init.headers.Authorization, 'Bearer k');
  assert.deepEqual(a.rules.phrases, ['rank higher']);

  await getSpamRules({ apiKey: 'k', fetch, now });
  assert.equal(calls.length, 1, 'fresh copy served from cache');

  t += 7 * 60 * 60 * 1000;
  response = () => new Response('down', { status: 503 });
  const b = await getSpamRules({ apiKey: 'k', fetch, now });
  assert.equal(calls.length, 2);
  assert.deepEqual(b.rules.phrases, ['rank higher'], 'last good copy kept');
  assert.match(b.error, /^HTTP 503/);

  await getSpamRules({ apiKey: 'k', fetch, now });
  assert.equal(calls.length, 2, 'no retry inside the cool-down');

  assert.equal(spamRulesEndpoint('https://staging.example/api/leads/'), 'https://staging.example/api/spam-rules');
  assert.equal(spamRulesEndpoint('https://staging.example/api/v2'), 'https://staging.example/api/v2/spam-rules');
  assert.equal(spamRulesEndpoint('https://staging.example/api/spam-rules'), 'https://staging.example/api/spam-rules', 'an explicit rules URL is used as is');
});

const req = () => new Request('https://www.example.com/api/contact', { method: 'POST', headers: { cookie: '', referer: 'https://www.example.com/contact/' } });

test('captureLead flags a matching submission as blocked spam and withholds the lead cookie', async () => {
  resetSpamRulesCache();
  const sent = [];
  const fetch = async (url, init) => {
    if (url.endsWith('/spam-rules')) return new Response(JSON.stringify({ phrases: ['guest post'] }), { status: 200 });
    sent.push(JSON.parse(init.body));
    return new Response(JSON.stringify({ id: 'q1' }), { status: 201 });
  };
  const r = await captureLead(req(), { email: 'spam@gmail.example', message: 'Want a Guest Post?' }, { apiKey: 'k', logger: false, fetch, spamFilter: true });
  assert.equal(r.ok, true);
  assert.equal(r.blocked, true);
  assert.deepEqual(r.spam, { type: 'phrases', rule: 'guest post', field: 'message' });
  assert.equal(r.cookie, null, 'no generate_lead for spam');
  assert.equal(sent.length, 1);
  assert.equal(sent[0].isSpam, true);
  assert.equal(sent[0].blocked, true);
  assert.equal(sent[0].spamReason, 'blocklist');
  assert.equal(sent[0].spamRule, 'phrases:guest post');
});

test('captureLead: site additions and exceptions, and a verdict even when the form is not a lead', async () => {
  resetSpamRulesCache();
  const fetch = async (url, init) => (url.endsWith('/spam-rules') ? new Response(JSON.stringify({ phrases: ['seo services'] }), { status: 200 }) : new Response('{}', { status: 201 }));
  const clean = await captureLead(req(), { email: 'a@example.com', message: 'We need seo services' }, { apiKey: 'k', logger: false, fetch, spamFilter: true, spamAllow: 'seo services' });
  assert.equal(clean.spam, null, 'exception lifts the global rule');
  assert.ok(clean.cookie, 'clean lead gets the cookie');

  const added = await captureLead(req(), { name: 'Nobody', message: 'cheap backlinks' }, { apiKey: 'k', logger: false, fetch, spamFilter: true, spamAdd: ['cheap backlinks'] });
  assert.equal(added.skipped, true, 'still not a lead');
  assert.equal(added.spam?.rule, 'cheap backlinks', 'but the verdict is there so the site can reject it');
});

test('captureLead: filter off by default, and spamRules skips the fetch', async () => {
  resetSpamRulesCache();
  const before = process.env.PPT_SPAM_FILTER;
  delete process.env.PPT_SPAM_FILTER;
  const urls = [];
  const fetch = async (url) => {
    urls.push(url);
    return new Response('{}', { status: 201 });
  };
  const off = await captureLead(req(), { email: 'a@example.com', message: 'guest post' }, { apiKey: 'k', logger: false, fetch });
  assert.equal(off.spam, null);
  assert.ok(!urls.some((u) => u.endsWith('/spam-rules')), 'no rules fetched when the filter is off');

  const local = await captureLead(req(), { email: 'a@example.com', message: 'guest post' }, { apiKey: 'k', logger: false, fetch, spamFilter: true, spamRules: { phrases: ['guest post'] } });
  assert.equal(local.spam?.type, 'phrases');
  assert.ok(!urls.some((u) => u.endsWith('/spam-rules')), 'explicit rules: nothing fetched');
  if (before !== undefined) process.env.PPT_SPAM_FILTER = before;
});

test('checkSpam gives the verdict on its own', async () => {
  resetSpamRulesCache();
  const fetch = async () => new Response(JSON.stringify({ domains: ['spammer.example'] }), { status: 200 });
  assert.equal((await checkSpam({ email: 'x@spammer.example' }, { apiKey: 'k', fetch }))?.type, 'domains');
  assert.equal(await checkSpam({ email: 'x@example.com' }, { apiKey: 'k', fetch }), null);
});
