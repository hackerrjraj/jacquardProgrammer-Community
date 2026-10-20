import bn from "./bn.json";
import enJson from "./en.json";

export type Dictionary = typeof bn;
export type Locale = "bn" | "en";

export const locales: Locale[] = ["bn", "en"];
export const defaultLocale: Locale = "bn";

// Cookie that remembers the chosen language (read by server and client).
export const LOCALE_COOKIE = "lang";
export const LOCALE_COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

// Typed as Dictionary so a missing key in en.json fails the build.
const en: Dictionary = enJson;

const dictionaries: Record<Locale, Dictionary> = { bn, en };

export function toLocale(value: string | undefined): Locale {
  return value === "en" ? "en" : defaultLocale;
}

export function dictionaryFor(locale: Locale): Dictionary {
  return dictionaries[locale];
}
