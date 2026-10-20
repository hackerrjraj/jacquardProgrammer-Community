import type { Dictionary, Locale } from "@/i18n/config";
import type { Role, User } from "./api";
import { localizeDigits } from "./format";

/** Same rule as the backend (App\Support\BdPhone::PATTERN). */
export const PHONE_RE = /^01[3-9][0-9]{8}$/;
export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
export const PASSWORD_MIN = 8;
export const PASSWORD_MAX = 100;

/** "০১৭" → "017" (Bengali keyboards type Bengali digits). */
export function toAsciiDigits(value: string): string {
  return value.replace(/[০-৯]/g, (d) => String(d.charCodeAt(0) - 0x09e6));
}

/** "+880 1712-345678" / "8801712345678" → "01712345678" (mirrors BdPhone::normalize). */
export function normalizePhone(value: string): string {
  const digits = toAsciiDigits(value).trim().replace(/[\s-]/g, "");
  const m = digits.match(/^\+?880(1[0-9]{9})$/);
  return m ? `0${m[1]}` : digits;
}

export function isAdminRole(role: Role | undefined): boolean {
  return role === "admin" || role === "super_admin";
}

/** Where to go right after login (or when an already logged-in user opens /login). */
export function homeFor(user: User): string {
  if (user.must_change_password) return "/change-password";
  return "/dashboard";
}

/** Only allow same-site paths for ?next= (no "//evil.com"). */
export function safeNext(next: string | null): string | null {
  return next && next.startsWith("/") && !next.startsWith("//") ? next : null;
}

export function formatDate(iso: string | null, locale: Locale): string {
  if (!iso) return "";
  return new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
    dateStyle: "medium",
    timeZone: "Asia/Dhaka",
  }).format(new Date(iso));
}

/** Replaces {name}-style placeholders. */
export function fill(text: string, values: Record<string, string | number>): string {
  return text.replace(/\{(\w+)\}/g, (_, key) => String(values[key] ?? ""));
}

// ---- Form errors ----
// Client errors keep a code (so they follow a language switch);
// server errors are already translated by the API (Accept-Language).

type ErrorCode = keyof Dictionary["auth"]["errors"];
export type FieldError = { code: ErrorCode; n?: number } | { code: "server"; text: string };

export function errorText(
  error: FieldError | undefined,
  t: Dictionary,
  locale: Locale,
): string | undefined {
  if (!error) return undefined;
  if (error.code === "server") return error.text;
  const text = t.auth.errors[error.code];
  const n = error.n === undefined ? "" : localizeDigits(String(error.n), locale);
  return text.replace(/\{(max|min)\}/g, n);
}

// ---- Machine / skill picks (list keys, Phase 11d-1) — same limits as the backend ----

export const PICKS_MAX = 20;

/** `other` = the "Other" text; a filled one counts as a pick. */
export function validatePicks(keys: string[], other?: string | null): FieldError | undefined {
  if (keys.length === 0 && !other?.trim()) return { code: "listPick" };
  if (keys.length > PICKS_MAX) return { code: "listMax", n: PICKS_MAX };
  return undefined;
}

/** 422 `errors` → first message per field ("machines.0" counts as "machines"). */
export function serverErrors<F extends string>(
  errors: Record<string, string[]> | undefined,
  fields: readonly F[],
): Partial<Record<F, FieldError>> {
  const out: Partial<Record<F, FieldError>> = {};
  for (const [key, messages] of Object.entries(errors ?? {})) {
    const field = key.split(".")[0] as F;
    if (fields.includes(field) && !out[field] && messages[0])
      out[field] = { code: "server", text: messages[0] };
  }
  return out;
}
