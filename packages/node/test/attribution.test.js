// Mirrors the attribution cases in tests/smoke/php-harness.php, so the Node
// port is held to the same results as the plugin.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { classify, forLead, parseCookies, readTouch, submittingPageUrl } from '../src/attribution.js';

const cookie = (obj) => encodeURIComponent(JSON.stringify(obj));

test('classify matches the PHP and tracker.js rules', () => {
  const cases = [
    [{ gclid: 'x' }, 'Paid Search'],
    [{ fbclid: 'x' }, 'Paid Social'],
    [{ source: 'facebook', medium: 'cpc' }, 'Paid Social'],
    [{ source: 'google', medium: 'cpc' }, 'Paid Search'],
    [{ source: 'google.com', medium: 'referral', referrer: 'google.com' }, 'Organic Search'],
    [{ source: 'google.co.uk', medium: 'referral', referrer: 'google.co.uk' }, 'Organic Search'],
    [{ source: 't.co', medium: 'referral', referrer: 't.co' }, 'Organic Social'],
    [{ source: 'l.facebook.com', medium: 'referral', referrer: 'l.facebook.com' }, 'Organic Social'],
    [{ source: 'partner.example.org', medium: 'referral', referrer: 'partner.example.org' }, 'Referral'],
    [{ source: 'mailchimp', medium: 'email' }, 'Email'],
    [{}, 'Direct'],
    [{ dclid: 'x' }, 'Display'],
  ];
  for (const [touch, expected] of cases) assert.equal(classify(touch), expected, JSON.stringify(touch));
});

test('cookie values are sanitized, never trusted', () => {
  const t = readTouch(
    {
      ppt_ft: cookie({
        s: '<script>alert(1)</script>google',
        m: ['nested'],
        c: 'A'.repeat(500),
        lp: 'https://evil.com/page?token=SECRET&utm_source=g&password=x',
        ch: 'Totally Fake Channel',
        ts: 'not-a-number',
        v: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        g: 'CjX',
      }),
    },
    'ppt_ft',
  );
  // Real WordPress (wp_strip_all_tags) drops <script> blocks with their
  // content; the PHP harness stubs that function, so it expects "alert(1)google".
  assert.equal(t.source, 'google');
  assert.equal(t.medium, undefined, 'array medium dropped');
  assert.equal(t.campaign.length, 200, 'campaign capped');
  assert.equal(t.landing_page, '/page?utm_source=g', 'landing page keeps only marketing params');
  assert.equal(t.channel, 'Paid Search', 'client channel replaced by server classification');
  assert.equal(t.timestamp, undefined, 'non-numeric timestamp dropped');
  assert.equal(t.visitor_id, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
});

test('garbage and oversize cookies read as empty', () => {
  assert.deepEqual(readTouch({ ppt_lt: 'not json at all' }, 'ppt_lt'), {});
  assert.deepEqual(readTouch({ ppt_lt: encodeURIComponent('{'.repeat(5000)) }, 'ppt_lt'), {});
});

test('cookies written by tracker.js parse from a raw Cookie header', () => {
  const header = `other=1; ppt_ft=${cookie({ s: 'google', m: 'cpc', g: 'CjX', lp: '/landing?gclid=CjX', ts: 1700000000, v: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' })}; ppt_lt=${cookie({ lp: '/pricing', ts: 1700090000, ch: 'Direct' })}`;
  const { first, last } = forLead(parseCookies(header));
  assert.equal(first.gclid, 'CjX');
  assert.equal(first.timestamp, 1700000000);
  assert.equal(last.landing_page, '/pricing');
  assert.equal(last.channel, 'Direct');
});

test('no last-touch cookie means a returning direct visit', () => {
  assert.deepEqual(forLead({}).last, { channel: 'Direct' });
});

test('submitting page comes from the referer, on this site only', () => {
  assert.equal(
    submittingPageUrl('https://www.example.com', 'https://example.com/contact/?session=abc&utm_campaign=spring'),
    'https://www.example.com/contact/?utm_campaign=spring',
  );
  assert.equal(submittingPageUrl('https://www.example.com', 'https://attacker.com/contact/'), null);
});
