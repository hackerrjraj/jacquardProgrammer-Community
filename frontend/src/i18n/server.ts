import { cookies } from "next/headers";
import type { Metadata } from "next";
import { cacheLife, cacheTag } from "next/cache";
import { cache } from "react";
import { API_URL } from "@/lib/api";
import {
  LOCALE_COOKIE,
  dictionaryFor,
  toLocale,
  type Dictionary,
  type Locale,
} from "./config";

/** Current language from the cookie (Server Components only). */
export async function getLocale(): Promise<Locale> {
  const store = await cookies();
  return toLocale(store.get(LOCALE_COOKIE)?.value);
}

/** Admin-edited page text (Phase 14a): {key: {bn?, en?}}, customised values only. {} when the API cannot be reached. */
export type SiteContent = Record<string, { bn?: string; en?: string }>;

/**
 * Cached (Next 16 "use cache"), so pages can be prerendered and generateMetadata() is not blocked by a live fetch.
 * Refreshed right after an admin saves (revalidateSiteContent server action → tag "site-content") and at least every 30 s.
 */
async function loadSiteContent(): Promise<SiteContent> {
  "use cache";
  cacheTag("site-content");
  cacheLife({ stale: 30, revalidate: 30, expire: 3600 });
  try {
    const res = await fetch(`${API_URL}/api/public/site-content`, {
      headers: { Accept: "application/json" },
      signal: AbortSignal.timeout(3000),
    });
    if (!res.ok) {
      cacheLife({ stale: 0, revalidate: 5, expire: 10 });
      return {};
    }
    const body = (await res.json()) as { data?: SiteContent };
    return body.data && typeof body.data === "object" ? body.data : {};
  } catch {
    cacheLife({ stale: 0, revalidate: 5, expire: 10 });
    return {};
  }
}

/** The text an admin saved for this key and language, or null (use the translation file). */
export async function getContentText(key: string, locale: Locale): Promise<string | null> {
  const value = (await loadSiteContent())[key]?.[locale];
  return typeof value === "string" && value.trim() !== "" ? value : null;
}

/** Sets dictionary[path] when that path is already a string (so unknown keys can never add or change anything else). */
function overlay(dict: Record<string, unknown>, path: string, value: string) {
  const parts = path.split(".");
  let node: unknown = dict;
  for (const part of parts.slice(0, -1)) {
    node = (node as Record<string, unknown> | undefined)?.[part];
    if (node === null || typeof node !== "object") return;
  }
  const last = parts[parts.length - 1];
  const holder = node as Record<string, unknown>;
  if (typeof holder[last] === "string") holder[last] = value;
}

const dictionaryWithContent = cache(async (locale: Locale): Promise<Dictionary> => {
  const content = await loadSiteContent();
  const keys = Object.keys(content).filter((k) => typeof content[k]?.[locale] === "string" && content[k][locale]!.trim() !== "");
  if (keys.length === 0) return dictionaryFor(locale);
  // Plain text only: values are rendered as React text, never as HTML.
  const copy = structuredClone(dictionaryFor(locale)) as unknown as Record<string, unknown>;
  for (const key of keys) overlay(copy, key, content[key][locale]!);
  return copy as unknown as Dictionary;
});

/**
 * Translations for the current language (Server Components only), with the admin-edited public-page text
 * (Site Content, Phase 14a) laid over the translation file. An empty / missing value keeps the translation file text.
 */
export async function getDictionary(): Promise<Dictionary> {
  return dictionaryWithContent(await getLocale());
}

/** Public pages that have an admin-editable SEO title + description (Site Content → SEO tab). */
const SEO_PAGES = ["about", "contact", "programmers", "gallery", "notices", "register"] as const;

/** Page <title> + description from the dictionary / Site Content SEO, e.g. pageMetadata("about"). */
export async function pageMetadata(
  page: keyof Dictionary["pages"],
): Promise<Metadata> {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  if (!(SEO_PAGES as readonly string[]).includes(page)) return { title: t.pages[page] };
  const [title, description] = await Promise.all([
    getContentText(`seo.${page}.title`, locale),
    getContentText(`seo.${page}.description`, locale),
  ]);
  return { title: title ?? t.pages[page], ...(description ? { description } : {}) };
}

/** Home page <title> + description (Site Content → SEO). Falls back to the site name / description. */
export async function homeMetadata(): Promise<Metadata> {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const [title, description] = await Promise.all([
    getContentText("seo.home.title", locale),
    getContentText("seo.home.description", locale),
  ]);
  return { title: { absolute: title ?? t.site.name }, description: description ?? t.site.description };
}

/** Dashboard page <title> from the sidebar labels, e.g. dashboardMetadata("payments"). */
export async function dashboardMetadata(
  key: keyof Dictionary["dashboard"]["nav"],
): Promise<Metadata> {
  const t = await getDictionary();
  return { title: t.dashboard.nav[key] };
}
