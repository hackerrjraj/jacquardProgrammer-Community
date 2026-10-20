import type { Locale } from "@/i18n/config";
import type { HeroSlide } from "@/lib/api";

/** Limits from the 11b-1 API (HeroSlide model / Admin\HeroSlideController). */
export const HERO_TITLE_MAX = 150;
export const HERO_SUBTITLE_MAX = 300;
export const HERO_BUTTON_MAX = 40;
export const HERO_LINK_MAX = 255;
export const HERO_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
export const HERO_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];

/** Title in the current language, falling back to the other one; null for a picture-only slide. */
export function heroSlideTitle(slide: Pick<HeroSlide, "title_bn" | "title_en">, locale: Locale): string | null {
  return (locale === "en" ? slide.title_en || slide.title_bn : slide.title_bn || slide.title_en) || null;
}

/**
 * Same rule as the API: a page of this site ("/register", never "//host") or a full https:// address.
 * The API checks again.
 */
export function isAllowedHeroLink(value: string): boolean {
  const link = value.trim();
  if (/^\/(?![/\\])\S*$/.test(link)) return true;
  if (!/^https:\/\/[^\s/?#]+\.[^\s/?#]+([/?#]\S*)?$/i.test(link)) return false;
  try {
    return new URL(link).protocol === "https:";
  } catch {
    return false;
  }
}
