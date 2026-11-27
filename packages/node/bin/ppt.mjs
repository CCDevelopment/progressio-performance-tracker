#!/usr/bin/env node
// npx ppt test   Sends a test lead to the dashboard with this site's key.
//
// Same job as `wp ppt test-lead` on WordPress. The lead is flagged test: true,
// so the dashboard stores it but keeps it out of the client's inbox, the
// form-fill count and GA4.
//
// Reads PPT_LEADS_API_KEY (and optionally PPT_LEADS_ENDPOINT, PPT_SITE_URL)
// from the environment, so run it with the site's env loaded, e.g.
//   node --env-file=.env.local node_modules/.bin/ppt test
//   vercel env pull && npx ppt test --env-file .env.local

import { readFileSync } from 'node:fs';
import { sendLead } from '../src/leads.js';
import { DEFAULT_ENDPOINT, VERSION } from '../src/version.js';

const args = process.argv.slice(2);
const command = args[0];

function flag(name) {
  const i = args.indexOf(`--${name}`);
  return i !== -1 ? args[i + 1] : undefined;
}

function loadEnvFile(path) {
  for (const line of readFileSync(path, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
  }
}

if (command !== 'test') {
  console.log(`Progressio Performance Tracker ${VERSION}

Usage:
  ppt test [--env-file .env.local] [--site https://example.com]

Sends a test lead (test: true) to the dashboard using PPT_LEADS_API_KEY.`);
  process.exit(command ? 1 : 0);
}

const envFile = flag('env-file');
if (envFile) loadEnvFile(envFile);

const apiKey = process.env.PPT_LEADS_API_KEY;
const endpoint = process.env.PPT_LEADS_ENDPOINT || DEFAULT_ENDPOINT;
const siteUrl = flag('site') || process.env.PPT_SITE_URL || '';

if (!apiKey) {
  console.error('PPT_LEADS_API_KEY is not set. Pass --env-file, or export it first.');
  process.exit(1);
}
if (apiKey === '[SENSITIVE]') {
  console.error('PPT_LEADS_API_KEY reads as [SENSITIVE]: Vercel will not export sensitive variables. Add a Development value or test on a Preview deployment.');
  process.exit(1);
}

const result = await sendLead(
  {
    name: 'Progressio test lead',
    email: 'ppt-test@progressiodev.com',
    message: 'Connection test from the Node package. Safe to ignore.',
    channel: 'Direct',
    formId: 'ppt-test',
    formTitle: 'Connection test',
    formPlugin: 'node',
    ...(siteUrl && { siteUrl }),
    pluginVersion: VERSION,
    submittedAt: new Date().toISOString(),
    test: true,
  },
  { apiKey, endpoint, siteUrl, timeoutMs: 10000 },
);

if (result.ok) {
  console.log(`OK: the dashboard accepted the test lead${result.leadId ? ` (id ${result.leadId})` : ''}.`);
  process.exit(0);
}
console.error(`Failed: ${result.error}`);
if (result.code === 401) console.error('The key was rejected. Copy it again from the client\'s Services modal in the dashboard.');
process.exit(1);
