import Image from "next/image";
import type { Locale } from "@/i18n/config";
import { Icon, isIconName, type IconName } from "@/components/ui/icon";
import type { ExpertiseCard } from "@/lib/api";

/** Limits from the 6d-1 API (ExpertiseCard model / controller). */
export const EXPERTISE_TITLE_MAX = 100;
export const EXPERTISE_TEXT_MAX = 500;
export const EXPERTISE_MAX_CARDS = 12;
export const EXPERTISE_IMAGE_MAX_BYTES = 2 * 1024 * 1024;
export const EXPERTISE_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];

/**
 * Icons offered in the admin form. The Home page draws any name of the site icon set (unknown → "chip");
 * this is the subset that suits an expertise card. Labels: dictionary `expertiseAdmin.icons`.
 */
export const EXPERTISE_ICONS = [
  "chip",
  "cog",
  "code",
  "settings",
  "network",
  "book",
  "trendingUp",
  "target",
  "users",
  "building",
  "image",
  "heart",
  "globe",
  "scale",
  "key",
  "checkCircle",
] as const satisfies readonly IconName[];

export type ExpertiseIcon = (typeof EXPERTISE_ICONS)[number];

/** The icon the Home page shows for a stored name (same rule as home-sections: unknown / empty → chip). */
export function homeIcon(name: string | null | undefined): IconName {
  return isIconName(name) ? name : "chip";
}

/** English falls back to Bengali when empty (same as the Home page). */
export function expertiseTitle(card: Pick<ExpertiseCard, "title_bn" | "title_en">, locale: Locale): string {
  return locale === "en" ? card.title_en || card.title_bn : card.title_bn;
}

/** Small preview: the picture when there is one, otherwise the icon in the Home page's blue-teal box. */
export function ExpertisePreview({ imageUrl, icon, className = "size-16" }: { imageUrl: string | null; icon: IconName; className?: string }) {
  return imageUrl ? (
    <div className={`relative shrink-0 overflow-hidden rounded-control bg-border ${className}`}>
      <Image src={imageUrl} alt="" fill unoptimized sizes="160px" className="object-cover" />
    </div>
  ) : (
    <div className={`flex shrink-0 items-center justify-center rounded-control bg-navy-band ${className}`}>
      <span className="flex size-10 items-center justify-center rounded-control bg-brand text-white shadow-sm">
        <Icon name={icon} className="size-5" />
      </span>
    </div>
  );
}
