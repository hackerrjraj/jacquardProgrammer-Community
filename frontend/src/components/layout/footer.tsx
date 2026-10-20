import Link from "next/link";
import { getDictionary, getLocale } from "@/i18n/server";
import { Container } from "@/components/ui/container";
import { Icon } from "@/components/ui/icon";
import { Logo } from "./logo";
import { navLinks } from "./nav-links";
import { contactLines, ContactValue, loadSiteInfo } from "./site-contact";

/** 4 columns on dark navy, divider, centred © line (MASTER_SPEC §8a). */
export async function Footer() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const f = t.footer;
  // Site Settings (Phase 11c): only the filled-in lines; no lines → no Contact Info column.
  const contactRows = contactLines(await loadSiteInfo(locale), t, locale);
  const communityLinks = [
    { href: "/register", label: f.joinCommunity },
    { href: "/programmers", label: f.directory },
    { href: "/gallery", label: t.nav.gallery },
  ];
  const linkClass = "text-white/70 transition-colors hover:text-primary";

  return (
    <footer className="mt-auto bg-footer text-white">
      <Container className={`grid gap-10 py-14 sm:grid-cols-2 ${contactRows.length > 0 ? "lg:grid-cols-4" : "lg:grid-cols-3"}`}>
        <div>
          <Logo lines={[t.site.logoLine1, t.site.logoLine2]} label={t.site.name} alwaysShowName />
          <p className="mt-4 text-sm leading-relaxed text-white/70">{f.about}</p>
        </div>

        <div>
          <h2 className="text-base font-semibold text-white">{f.quickLinks}</h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            {navLinks.map(({ href, key }) => (
              <li key={href}>
                <Link href={href} className={linkClass}>
                  {t.nav[key]}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        <div>
          <h2 className="text-base font-semibold text-white">{f.community}</h2>
          <ul className="mt-4 space-y-2.5 text-sm">
            {communityLinks.map(({ href, label }) => (
              <li key={href}>
                <Link href={href} className={linkClass}>
                  {label}
                </Link>
              </li>
            ))}
          </ul>
        </div>

        {contactRows.length > 0 && (
          <div>
            <h2 className="text-base font-semibold text-white">{f.contactInfo}</h2>
            <ul className="mt-4 space-y-3 text-sm">
              {contactRows.map((line) => (
                <li key={line.key} className="flex items-start gap-2.5">
                  <Icon name={line.icon} className="mt-0.5 size-4 shrink-0 text-primary" />
                  <span className="min-w-0 break-words whitespace-pre-line">
                    <span className="sr-only">{line.label}: </span>
                    {line.href ? (
                      <ContactValue line={line} className={linkClass} />
                    ) : (
                      <span className="text-white/70">{line.text}</span>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Container>

      <div className="border-t border-footer-line">
        <Container className="py-5 text-center text-sm text-white/60">
          {f.copyright}
        </Container>
      </div>
    </footer>
  );
}
