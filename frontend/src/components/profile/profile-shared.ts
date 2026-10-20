"use client";

import { useEffect, useState } from "react";
import type { Dictionary, Locale } from "@/i18n/config";
import { apiFetch, type ApiResult, type Option, type ProfileOptions } from "@/lib/api";
import { localizeDigits } from "@/lib/format";

// Same limits as the backend (MASTER_SPEC §19, §38).
export const PHOTO_MAX_BYTES = 2 * 1024 * 1024;
export const PHOTO_TYPES = ["image/jpeg", "image/png", "image/webp"];
export const DOCUMENT_MAX_BYTES = 5 * 1024 * 1024;
export const DOCUMENT_TYPES = ["application/pdf", "image/jpeg", "image/png"];
export const DOCUMENTS_MAX = 20;
export const PORTFOLIO_MAX = 12;
export const WORK_HISTORY_MAX = 15;
export const MIN_YEAR = 1950;

/** Current year in Bangladesh time. */
export function thisYear(): number {
  return Number(new Intl.DateTimeFormat("en", { year: "numeric", timeZone: "Asia/Dhaka" }).format(new Date()));
}

/** Fixed lists from GET /api/profile-options (null while loading / on failure). */
export function useProfileOptions(): ProfileOptions | null {
  const [options, setOptions] = useState<ProfileOptions | null>(null);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: ProfileOptions }>("/profile-options").then((res) => {
      if (!cancelled && res.ok) setOptions(res.data.data);
    });
    return () => {
      cancelled = true;
    };
  }, []);
  return options;
}

/** bn/en label of a stored key (falls back to the key itself). */
export function optionLabel(list: Option[] | undefined, key: string | null, locale: Locale): string {
  if (!key) return "";
  const option = list?.find((o) => o.key === key);
  return option ? (locale === "bn" ? option.name_bn : option.name_en) : key;
}

/** 422 errors → first message per field, keyed by the full name ("work_history.0.end_year"). */
export function fieldErrors(res: ApiResult<unknown>): Record<string, string> {
  if (res.ok || res.status !== 422) return {};
  const out: Record<string, string> = {};
  for (const [key, messages] of Object.entries(res.data?.errors ?? {})) {
    if (messages[0]) out[key] = messages[0];
  }
  return out;
}

/** A message for a failed request: the API's own (already bn/en) or a network error. */
export function failureText(res: ApiResult<unknown>, t: Dictionary): string {
  if (res.ok) return "";
  if (res.status === 0) return t.auth.networkError;
  return res.data?.message ?? t.auth.genericError;
}

/** 1536000 → "1.5 MB" (Bengali digits in bn). */
export function formatSize(bytes: number, locale: Locale): string {
  const text = bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
  return localizeDigits(text, locale);
}

/** Checks a chosen file against the allowed types and size; returns an error text or null. */
export function checkFile(
  file: File,
  types: string[],
  maxBytes: number,
  messages: { badType: string; tooBig: string },
): string | null {
  if (!types.includes(file.type)) return messages.badType;
  if (file.size > maxBytes) return messages.tooBig;
  return null;
}
