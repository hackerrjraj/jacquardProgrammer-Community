import { cacheLife, cacheTag } from "next/cache";
import type { Dictionary, Locale } from "@/i18n/config";
import { Icon, type IconName } from "@/components/ui/icon";
import { apiFetch, type SiteInfo } from "@/lib/api";
import { localizeDigits } from "@/lib/format";

/** Site contact info + About text (Phase 11c), or null when the API cannot be reached. Server Components only. */
export async function loadSiteInfo(locale: Locale): Promise<SiteInfo | null> {
  "use cache";
  // Cached like Site Content: refreshed when an admin saves Site Settings (revalidateSiteContent) and at least every 30 s.
  cacheTag("site-content");
  cacheLife({ stale: 30, revalidate: 30, expire: 3600 });
  const res = await apiFetch<{ data: SiteInfo }>("/public/site-info", { locale });
  if (!res.ok) cacheLife({ stale: 0, revalidate: 5, expire: 10 });
  return res.ok ? res.data.data : null;
}

/** A bn / en text: the current language, or the other one when that is empty. */
export function localized(info: SiteInfo | null, field: "address" | "office_hours" | "about", locale: Locale): string | null {
  if (!info) return null;
  const other: Locale = locale === "bn" ? "en" : "bn";
  return info[`${field}_${locale}`] || info[`${field}_${other}`] || null;
}

export type ContactLine = { key: string; icon: IconName; label: string; text: string; href?: string; external?: boolean };

/** Only the filled-in fields — an empty field gives no line at all (no empty labels). */
export function contactLines(info: SiteInfo | null, t: Dictionary, locale: Locale): ContactLine[] {
  if (!info) return [];
  const f = t.footer;
  const lines: (ContactLine | null)[] = [
    info.phone_1 ? { key: "phone_1", icon: "phone", label: f.phoneLabel, text: localizeDigits(info.phone_1, locale), href: `tel:${info.phone_1}` } : null,
    info.phone_2 ? { key: "phone_2", icon: "phone", label: f.phoneLabel, text: localizeDigits(info.phone_2, locale), href: `tel:${info.phone_2}` } : null,
    info.whatsapp
      ? {
          key: "whatsapp",
          icon: "chat",
          label: f.whatsappLabel,
          text: localizeDigits(info.whatsapp, locale),
          // wa.me needs the number with the country code and no leading 0.
          href: `https://wa.me/88${info.whatsapp}`,
          external: true,
        }
      : null,
    info.email ? { key: "email", icon: "mail", label: f.emailLabel, text: info.email, href: `mailto:${info.email}` } : null,
    info.facebook_url
      ? { key: "facebook", icon: "globe", label: f.facebookLabel, text: f.facebookText, href: info.facebook_url, external: true }
      : null,
  ];
  const address = localized(info, "address", locale);
  const hours = localized(info, "office_hours", locale);
  if (address) lines.push({ key: "address", icon: "mapPin", label: f.addressLabel, text: address });
  if (hours) lines.push({ key: "hours", icon: "clock", label: f.officeHoursLabel, text: hours });
  return lines.filter((l): l is ContactLine => l !== null);
}

/** Icon-in-circle list used on the Contact and About pages. */
export function ContactList({ lines, className = "" }: { lines: ContactLine[]; className?: string }) {
  return (
    <dl className={`space-y-4 ${className}`}>
      {lines.map((line) => (
        <div key={line.key} className="flex items-start gap-3">
          <span className="flex size-10 shrink-0 items-center justify-center rounded-full border border-border bg-surface text-primary-text shadow-card">
            <Icon name={line.icon} className="size-4" />
          </span>
          <div className="min-w-0">
            <dt className="text-sm font-medium text-muted">{line.label}</dt>
            <dd className="mt-0.5 break-words whitespace-pre-line">
              <ContactValue line={line} className="font-medium text-secondary hover:underline" />
            </dd>
          </div>
        </div>
      ))}
    </dl>
  );
}

export function ContactValue({ line, className }: { line: ContactLine; className: string }) {
  if (!line.href) return <>{line.text}</>;
  return (
    <a href={line.href} className={className} {...(line.external ? { target: "_blank", rel: "noopener noreferrer" } : {})}>
      {line.text}
    </a>
  );
}
