"use client";

import { useRouter } from "next/navigation";
import { useTransition } from "react";
import { LOCALE_COOKIE, LOCALE_COOKIE_MAX_AGE } from "@/i18n/config";
import { useDictionary, useLocale } from "@/i18n/client";
import { syncPushLanguage } from "@/components/pwa/push-client";

export function LanguageToggle({
  className,
  dark = false,
}: {
  className?: string;
  /** On a dark background (header) */
  dark?: boolean;
}) {
  const locale = useLocale();
  const t = useDictionary();
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  function toggle() {
    const next = locale === "bn" ? "en" : "bn";
    document.cookie = `${LOCALE_COOKIE}=${next}; path=/; max-age=${LOCALE_COOKIE_MAX_AGE}; SameSite=Lax`;
    document.documentElement.lang = next;
    // Push on for this device → pushes are written in the new language too (Phase 10c-2).
    void syncPushLanguage(next);
    // Re-render the server parts of the page with the new cookie.
    startTransition(() => router.refresh());
  }

  return (
    <button
      type="button"
      onClick={toggle}
      disabled={pending}
      aria-label={t.language.switchLabel}
      lang={locale === "bn" ? "en" : "bn"}
      className={`inline-flex h-9 items-center gap-1.5 rounded-full border px-3 text-sm font-semibold transition-colors disabled:opacity-60 ${
        dark
          ? "border-white/25 text-white hover:border-primary hover:text-primary"
          : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
      } ${className ?? ""}`}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        className="size-4"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <circle cx="12" cy="12" r="10" />
        <path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20" />
      </svg>
      {t.language.switchTo}
    </button>
  );
}
