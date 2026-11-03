export { captureLead, applyLeadCookie, buildPayload, sendLead, checkSpam } from './leads.js';
export { evaluateSpam, parseRuleLines, effectiveRules, getSpamRules, matchValue } from './spam.js';
export { trackerConfig, trackerScripts, trackerHead } from './head.js';
export { extractContact, normaliseFields } from './contact.js';
export { classify } from './attribution.js';
export { VERSION } from './version.js';
