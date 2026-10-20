import { getDictionary, getLocale, pageMetadata } from "@/i18n/server";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { getSiteImage } from "@/lib/site-images";
import { PageHero } from "@/components/ui/page-hero";
import { ContactForm } from "@/components/contact/contact-form";
import { ContactList, contactLines, loadSiteInfo } from "@/components/layout/site-contact";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("contact");

export default async function Page() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  // Site Settings (Phase 11c): only the filled-in lines. Nothing filled in → no info card, the form takes the full width.
  const lines = contactLines(await loadSiteInfo(locale), t, locale);

  return (
    <>
      <PageHero bgImage={await getSiteImage("header_contact")} eyebrow={t.eyebrows.contact} title={t.pages.contact} subtitle={t.contact.subtitle} />
      <Container className="py-12 sm:py-16">
        <div className={lines.length > 0 ? "grid gap-6 lg:grid-cols-3" : "mx-auto max-w-3xl"}>
          {lines.length > 0 && (
            <Card className="h-fit p-6">
              <h2 className="text-xl font-semibold">{t.contact.infoTitle}</h2>
              <ContactList lines={lines} className="mt-5" />
            </Card>
          )}

          <div className={lines.length > 0 ? "lg:col-span-2" : ""}>
            <ContactForm />
          </div>
        </div>
      </Container>
    </>
  );
}
