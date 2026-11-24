/**
 * The app's one way into Nextcloud's translations.
 *
 * Every user-visible string goes through t() or n() from here, so the msgids
 * match l10n/nl.json character for character. Nextcloud loads that bundle
 * itself (Util::addScript pulls in l10n/<lang>.js), so a user whose language is
 * Dutch sees Dutch and everyone else keeps the English msgid.
 *
 * escape and sanitize are off on purpose. Vue already escapes mustache and
 * attribute output, and the library's defaults would HTML-escape on top of that
 * — DOMPurify serialises "R&D" as "R&amp;D" — so users would read entities.
 * Never feed a result of t()/n() to v-html.
 *
 * Plain Node (the tests) has no bundle registered, so t() and n() return the
 * English msgid with its placeholders filled in.
 */
import { translate, translatePlural, getCanonicalLocale } from "@nextcloud/l10n";

const APP_ID = "projectcreatoraio";
const OPTIONS = { escape: false, sanitize: false };

export function t(text, vars) {
  return translate(APP_ID, text, vars, undefined, OPTIONS);
}

/**
 * n() with the count printed the user's way: 4.5 becomes "4,5" in Dutch.
 * The library prints %n with toString(), so a fractional count is swapped for
 * its formatted form afterwards; whole numbers are left exactly as they were.
 */
export function n(singular, plural, count, vars) {
  const text = translatePlural(APP_ID, singular, plural, count, vars, OPTIONS);
  if (typeof count !== "number" || Number.isInteger(count)) {
    return text;
  }
  return text.split(String(count)).join(formatNumber(count));
}

/**
 * t() for an English word that means two things in different places, such as
 * "Open" as a button (Openen) and as a task status (Open). The Dutch bundle
 * holds the key "text|context"; anyone without a translation for that key
 * sees the plain English text, never the key.
 */
export function tc(text, context, vars) {
  const key = `${text}|${context}`;
  return t(key) === key ? t(text, vars) : t(key, vars);
}

/** A number in the user's locale, at most one decimal: 4.5 → "4,5" in Dutch. */
export function formatNumber(value, maximumFractionDigits = 1) {
  try {
    return new Intl.NumberFormat(locale(), { maximumFractionDigits }).format(value);
  } catch {
    return String(value);
  }
}

/** The user's Nextcloud locale as a BCP 47 tag, for Intl and toLocale*(). */
export function locale() {
  try {
    return getCanonicalLocale() || undefined;
  } catch {
    return undefined;
  }
}
