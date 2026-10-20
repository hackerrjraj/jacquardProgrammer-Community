import type { Locale } from "@/i18n/config";
import { formatDateTime } from "@/components/notices/notice-text";

/** Date + time in Dhaka time; Bengali gets পূর্বাহ্ণ / অপরাহ্ণ instead of AM / PM (same as the chat list, 9d-2). */
export function formatWhen(iso: string | null, locale: Locale): string {
  const text = formatDateTime(iso, locale);
  return locale === "bn" ? text.replace(/\bAM\b/, "পূর্বাহ্ণ").replace(/\bPM\b/, "অপরাহ্ণ") : text;
}
