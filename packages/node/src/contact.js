// Port of PPT_Leads::extract_contact() and its helpers (includes/class-ppt-leads.php).
// Keep the heuristics identical, so the same form yields the same lead on
// WordPress and on Node.

import { isEmail, looksLikePhone, sanitizeText, sanitizeTextarea } from './sanitize.js';

/**
 * @typedef {{ id: string, label: string, type: string, value: string }} Field
 */

/**
 * Normalise what a site hands us into the PHP field shape.
 *
 * Accepts either an array of { id, label?, type?, value } or a plain object of
 * name → value, as a form's FormData or JSON body usually arrives. For plain
 * objects the label is the name with _ - . turned into spaces, so
 * "first_name" reads as "first name" to the heuristics, the way a WordPress
 * form plugin's label would.
 *
 * @returns {Field[]}
 */
export function normaliseFields(input) {
  const toValue = (v) => {
    if (v === null || v === undefined) return '';
    if (Array.isArray(v)) return v.filter((x) => x !== null && x !== undefined && x !== '').map(String).join(', ');
    if (typeof v === 'object') return '';
    return String(v).trim();
  };

  if (Array.isArray(input)) {
    return input
      .filter((f) => f && typeof f === 'object')
      .map((f) => ({
        id: String(f.id ?? f.name ?? ''),
        label: String(f.label ?? ''),
        type: String(f.type ?? '').toLowerCase(),
        value: toValue(f.value),
      }));
  }

  if (input && typeof input === 'object') {
    return Object.entries(input).map(([id, value]) => ({
      id,
      label: id.replace(/[_.-]+/g, ' ').trim(),
      type: '',
      value: toValue(value),
    }));
  }

  return [];
}

function firstMatch(fields, test) {
  for (const f of fields) {
    if (f.value !== '' && test(f)) return f.value;
  }
  return '';
}

/**
 * Resolve a mapping key ("email", "field_12", "First Name|Last Name") to a
 * value. Keys are matched case-insensitively against the field id and label;
 * "|" joins several fields with a space.
 */
function lookupMapped(fields, spec) {
  const parts = [];
  for (let key of String(spec).split('|')) {
    key = key.trim().toLowerCase();
    if (key === '') continue;
    const f = fields.find((x) => x.id.toLowerCase() === key || x.label.toLowerCase() === key);
    if (f && f.value !== '') parts.push(f.value);
  }
  return parts.join(' ').trim();
}

/**
 * Resolve name / email / phone / message from normalised fields.
 *
 * An explicit mapping ({ name: 'first_name|last_name', email: 'email' }) wins
 * for whichever roles it defines; label/type heuristics fill the rest.
 *
 * @param {Field[]} fields
 * @param {Partial<Record<'name'|'email'|'phone'|'message', string>>} [map]
 */
export function extractContact(fields, map) {
  const out = { name: '', email: '', phone: '', message: '' };

  if (map) {
    for (const role of Object.keys(out)) {
      if (map[role]) out[role] = lookupMapped(fields, map[role]);
    }
  }

  // ── Email ──────────────────────────────────────────────────────────────────
  if (out.email === '') {
    out.email =
      firstMatch(fields, (f) => f.type === 'email' && isEmail(f.value)) ||
      firstMatch(fields, (f) => /\be-?mail\b/i.test(`${f.label} ${f.id}`) && isEmail(f.value)) ||
      firstMatch(fields, (f) => !['textarea', 'message'].includes(f.type) && isEmail(f.value));
  }

  // ── Phone ──────────────────────────────────────────────────────────────────
  if (out.phone === '') {
    const phoneRe = /\b(phone|mobile|tel|telephone|cell|cellphone|contact number|whatsapp)\b|phone_?number|\btel_?no\b/i;
    out.phone =
      firstMatch(fields, (f) => ['tel', 'phone', 'phone_number'].includes(f.type) && looksLikePhone(f.value)) ||
      firstMatch(fields, (f) => phoneRe.test(`${f.label} ${f.id}`) && looksLikePhone(f.value));
  }

  // ── Name ───────────────────────────────────────────────────────────────────
  if (out.name === '') {
    const notPerson = /(company|business|organi[sz]ation|user|file|product|pet|street|website|domain|form|nick|middle|title)/i;

    // A field is a name *part* when it mentions first/last AND is clearly a
    // name field (label says "name", type is "name", or the id ends in
    // ".first"/".last" as WPForms and Gravity Forms sub-inputs do).
    const nameCtx = (f, part) =>
      f.type === 'name' ||
      f.label.toLowerCase().includes('name') ||
      f.id.toLowerCase().includes('name') ||
      new RegExp(`(^|[._-])${part}$`).test(f.id.toLowerCase());
    const isFirst = (f) => /first|given|fname/i.test(`${f.label} ${f.id}`) && nameCtx(f, 'first');
    const isLast = (f) => /last|surname|family|lname/i.test(`${f.label} ${f.id}`) && nameCtx(f, 'last');

    let full = firstMatch(fields, (f) => f.type === 'name' && !isFirst(f) && !isLast(f));
    if (full === '') {
      full = `${firstMatch(fields, isFirst)} ${firstMatch(fields, isLast)}`.trim();
    }
    if (full === '') {
      full = firstMatch(
        fields,
        (f) => /\bname\b/i.test(`${f.label} ${f.id}`) && !notPerson.test(`${f.label} ${f.id}`) && !isEmail(f.value),
      );
    }
    out.name = full;
  }

  // ── Message ────────────────────────────────────────────────────────────────
  if (out.message === '') {
    const msgRe = /(message|comment|enquir|inquir|details|question|notes|how can we help|tell us|describe|project|requirements)/i;
    out.message =
      firstMatch(fields, (f) => ['textarea', 'message'].includes(f.type)) ||
      firstMatch(fields, (f) => msgRe.test(`${f.label} ${f.id}`));
  }

  // ── Sanitize ───────────────────────────────────────────────────────────────
  const clean = {
    name: sanitizeText(out.name).slice(0, 150),
    email: isEmail(out.email) ? out.email.trim().toLowerCase() : '',
    phone: looksLikePhone(out.phone) ? sanitizeText(out.phone).slice(0, 40) : '',
    message: sanitizeTextarea(out.message).slice(0, 2000),
  };

  return Object.fromEntries(Object.entries(clean).filter(([, v]) => v !== ''));
}
