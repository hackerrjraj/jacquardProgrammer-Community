import type { Locale } from "@/i18n/config";
import type { Notice } from "@/lib/api";

// Plain helpers (no React) — usable on the server and in the browser.

// Same limits as the backend (Notice::MAX_KB, Notice::ATTACHMENT_MIMES, Admin\NoticeController rules).
export const NOTICE_MAX_BYTES = 5 * 1024 * 1024;
export const NOTICE_TYPES = ["application/pdf", "image/jpeg", "image/png", "image/webp"];
export const TITLE_MAX = 200;
export const BODY_MAX = 10000;

/** Title in the chosen language; English falls back to Bengali when empty. */
export function noticeTitle(n: Pick<Notice, "title_bn" | "title_en">, locale: Locale): string {
  return locale === "en" ? n.title_en || n.title_bn : n.title_bn;
}

/** Text in the chosen language; English falls back to Bengali when empty. */
export function noticeBody(n: Pick<Notice, "body_bn" | "body_en">, locale: Locale): string {
  return locale === "en" ? n.body_en || n.body_bn : n.body_bn;
}

/** First ~180 characters on one line, for the list. */
export function excerpt(text: string, max = 180): string {
  const flat = text.replace(/\s+/g, " ").trim();
  return flat.length > max ? `${flat.slice(0, max).trimEnd()}…` : flat;
}

/** "13 Oct 2026, 10:30" in Bangladesh time. */
export function formatDateTime(iso: string | null, locale: Locale): string {
  if (!iso) return "";
  return new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Dhaka",
  }).format(new Date(iso));
}
