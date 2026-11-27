// Small ports of the WordPress sanitizers the PHP side relies on, so a lead
// sent from a Node site is cleaned the same way as one sent from WordPress.

/** wp_strip_all_tags(): drop script/style blocks with their content, then every tag. */
function stripTags(value) {
  return String(value)
    .replace(/<(script|style)[^>]*?>[\s\S]*?<\/\1>/gi, '')
    .replace(/<[^>]*>/g, '');
}

/** sanitize_text_field(): no tags, no line breaks, collapsed whitespace. */
export function sanitizeText(value) {
  return stripTags(value)
    .replace(/[\r\n\t ]+/g, ' ')
    .trim();
}

/** sanitize_textarea_field(): as sanitize_text_field() but keeps line breaks. */
export function sanitizeTextarea(value) {
  return stripTags(value)
    .replace(/\r\n?/g, '\n')
    .split('\n')
    .map((line) => line.replace(/[\t ]+/g, ' ').trim())
    .join('\n')
    .trim();
}

/** is_email(): the same checks WordPress makes, in the same order. */
export function isEmail(value) {
  const email = String(value ?? '');
  if (email.length < 6) return false;
  const at = email.indexOf('@', 1);
  if (at === -1) return false;

  const local = email.slice(0, at);
  const domain = email.slice(at + 1);
  if (!/^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test(local)) return false;
  if (/\.{2,}/.test(domain)) return false;
  if (domain.replace(/^[ \t\n\r\0\x0B.]+|[ \t\n\r\0\x0B.]+$/g, '') !== domain) return false;

  const subs = domain.split('.');
  if (subs.length < 2) return false;
  for (const sub of subs) {
    if (sub.replace(/^[ \t\n\r\0\x0B-]+|[ \t\n\r\0\x0B-]+$/g, '') !== sub) return false;
    if (!/^[a-z0-9-]+$/i.test(sub)) return false;
  }
  return true;
}

/** PPT_Leads::looks_like_phone(). */
export function looksLikePhone(value) {
  const str = String(value ?? '');
  const digits = str.replace(/\D+/g, '');
  return digits.length >= 6 && digits.length <= 20 && str.length <= 40;
}
