import type { Locale } from "@/i18n/config";
import type { GalleryImage } from "@/lib/api";

// Same limits as the backend (Admin\GalleryController, GalleryImage::MAX_KB / MAX_PER_UPLOAD).
export const GALLERY_MAX_BYTES = 5 * 1024 * 1024;
export const GALLERY_MAX_FILES = 20;
export const GALLERY_TYPES = ["image/jpeg", "image/png", "image/webp"];
/** Category code rule (same regex as the backend). */
export const CATEGORY_KEY = /^[a-z0-9]+(-[a-z0-9]+)*$/;

/** Photo title in the chosen language, falling back to the other one (titles are optional). */
export function photoTitle(image: Pick<GalleryImage, "title_bn" | "title_en">, locale: Locale): string | null {
  return (locale === "bn" ? image.title_bn || image.title_en : image.title_en || image.title_bn) || null;
}

/** bn/en name of a category. */
export function categoryName(category: { name_bn: string; name_en: string }, locale: Locale): string {
  return locale === "bn" ? category.name_bn : category.name_en;
}
