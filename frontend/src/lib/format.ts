import type { Locale } from "@/i18n/config";

const BENGALI_DIGITS = "০১২৩৪৫৬৭৮৯";

/** Replaces 0-9 with Bengali digits when the language is bn. */
export function localizeDigits(text: string, locale: Locale): string {
  if (locale !== "bn") return text;
  return text.replace(/[0-9]/g, (d) => BENGALI_DIGITS[Number(d)]);
}

/** 125000 → "1,25,000" (en) or "১,২৫,০০০" (bn). South Asian grouping. */
export function formatNumber(value: number, locale: Locale): string {
  return localizeDigits(Math.round(value).toLocaleString("en-IN"), locale);
}

/** Whole-taka amount with the ৳ sign, e.g. "৳ ১,২০০". */
export function formatTaka(value: number, locale: Locale): string {
  return `৳ ${formatNumber(value, locale)}`;
}

/** bn or en name of a list item ({key, name_bn, name_en}), e.g. a machine or skill (Phase 11d-1). */
export function optionName(option: { name_bn: string; name_en: string }, locale: Locale): string {
  return locale === "bn" ? option.name_bn : option.name_en;
}

/** The saved list items plus the member's free "Other" text as one more normal tag. */
export function withOther<T extends { key: string; name_bn: string; name_en: string }>(
  options: T[],
  other?: string | null,
): { key: string; name_bn: string; name_en: string }[] {
  const text = other?.trim();
  return text ? [...options, { key: "other", name_bn: text, name_en: text }] : options;
}

/** Names of saved list items, joined for one line: "STOLL, SHIMA SEIKI". */
export function optionNames(options: { name_bn: string; name_en: string }[], locale: Locale): string {
  return options.map((o) => optionName(o, locale)).join(", ");
}
