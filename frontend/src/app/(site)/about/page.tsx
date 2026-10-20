
import { getDictionary, getLocale, pageMetadata } from "@/i18n/server";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { Icon, type IconName } from "@/components/ui/icon";
import Image from "next/image";
import { getSiteImage } from "@/lib/site-images";
import { PageHero } from "@/components/ui/page-hero";
import { SectionHeading } from "@/components/ui/section-heading";
import { ContactForm } from "@/components/contact/contact-form";
import { BrandVisual } from "@/components/home/brand-visual";
import { ContactList, contactLines, loadSiteInfo, localized } from "@/components/layout/site-contact";
import { Avatar } from "@/components/profile/profile-view";
import { apiFetch, type CommitteeMember } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("about");

/** Icons of the "How we support our members" cards, in dictionary order. */
const SUPPORT_ICONS: IconName[] = ["network", "book", "calendar", "trendingUp"];

// About — MASTER_SPEC §14 / About screenshot: Who we are, Mission + Vision,
// How we support our members, How the fund works (§14 "how the fund works"), contact block.
// Phase 11c: the "Who we are" text comes from Site Settings (built-in dictionary text when empty), the committee
// list is built automatically (name, photo, position only), and the contact lines show only filled-in fields.
export default async function Page() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const a = t.about;
  const [info, committeeRes] = await Promise.all([
    loadSiteInfo(locale),
    apiFetch<{ data: CommitteeMember[] }>("/public/committee", { locale }),
  ]);
  const contactRows = contactLines(info, t, locale);
  const committee = committeeRes.ok ? committeeRes.data.data : [];
  // Paragraphs are separated by an empty line.
  const [headerImage, bannerImage] = await Promise.all([getSiteImage("header_about"), getSiteImage("about_banner")]);
  const whoParagraphs = (localized(info, "about", locale) ?? a.whoText).split(/\n\s*\n/).filter((p) => p.trim() !== "");

  return (
    <>
      <PageHero
        bgImage={headerImage}
        eyebrow={t.eyebrows.about}
        title={fill(a.heroTitle, { name: t.site.fullName })}
        subtitle={a.heroSubtitle}
      />

      {/* Banner picture (Site Content → Images); nothing when none is uploaded */}
      {bannerImage && (
        <section className="bg-surface pt-10 sm:pt-14">
          <Container>
            <div className="relative aspect-[16/5] overflow-hidden rounded-card shadow-image ring-1 ring-black/5">
              <Image src={bannerImage} alt="" fill sizes="(min-width: 1280px) 1200px, 100vw" className="object-cover" />
            </div>
          </Container>
        </section>
      )}

      {/* Who we are */}
      <section className="bg-surface py-16 sm:py-24">
        <Container className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
          <div>
            <p className="eyebrow mb-2">{a.whoEyebrow}</p>
            <h2 className="text-2xl font-bold sm:text-3xl">{a.whoTitle}</h2>
            {whoParagraphs.map((paragraph, i) => (
              <p key={i} className="mt-4 leading-relaxed whitespace-pre-line">
                {paragraph.trim()}
              </p>
            ))}
          </div>
          <BrandVisual caption={t.site.fullName} />
        </Container>
      </section>

      {/* Committee (automatic, Phase 11c) — directly below the About text, same background; hidden when nobody to show */}
      {committee.length > 0 && (
        <section className="bg-surface pb-16 sm:pb-24">
          <Container>
            <SectionHeading eyebrow={a.committeeEyebrow} title={a.committeeTitle} subtitle={a.committeeSubtitle} />
            <ul className="mt-10 grid grid-cols-2 gap-4 sm:gap-5 md:grid-cols-3 lg:grid-cols-4">
              {committee.map((member, i) => (
                <li key={i}>
                  <Card className="flex h-full flex-col items-center p-4! text-center sm:p-6!">
                    <Avatar name={member.name} url={member.photo_url} className="size-20 sm:size-24" rounded="rounded-full" />
                    <h3 className="mt-4 text-base font-semibold break-words">{member.name}</h3>
                    {member.position && <p className="mt-1 text-sm font-medium break-words text-primary-text">{member.position}</p>}
                  </Card>
                </li>
              ))}
            </ul>
          </Container>
        </section>
      )}

      {/* Mission + Vision */}
      <section className="bg-background py-16 sm:py-24">
        <Container className="grid gap-6 md:grid-cols-2">
          <Card className="p-6! sm:p-8!">
            <Icon name="target" className="size-7 text-primary-text" />
            <h2 className="mt-4 text-xl font-bold">{a.missionTitle}</h2>
            <ul className="mt-5 space-y-3">
              {a.mission.map((item) => (
                <li key={item} className="flex items-start gap-3">
                  <Icon name="checkCircle" className="mt-0.5 size-5 shrink-0 text-primary-text" />
                  <span>{item}</span>
                </li>
              ))}
            </ul>
          </Card>
          <div className="rounded-card bg-navy-band p-6 text-white shadow-image sm:p-8">
            <Icon name="eye" className="size-7 text-primary" />
            <h2 className="mt-4 text-xl font-bold text-white">{a.visionTitle}</h2>
            <p className="mt-5 text-lg leading-relaxed text-white/90 sm:text-xl">{a.visionText}</p>
          </div>
        </Container>
      </section>

      {/* How we support our members */}
      <section className="bg-surface py-16 sm:py-24">
        <Container>
          <SectionHeading eyebrow={a.supportEyebrow} title={a.supportTitle} />
          <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {a.support.map((item, i) => (
              <li key={item.title}>
                <Card className="h-full">
                  <span className="flex size-11 items-center justify-center rounded-control bg-icon-box text-white">
                    <Icon name={SUPPORT_ICONS[i] ?? "users"} className="size-5" />
                  </span>
                  <h3 className="mt-4 text-base font-semibold">{item.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed">{item.text}</p>
                </Card>
              </li>
            ))}
          </ul>
        </Container>
      </section>

      {/* How the fund works */}
      <section className="bg-background py-16 sm:py-24">
        <Container>
          <SectionHeading eyebrow={a.fundEyebrow} title={a.fundTitle} subtitle={a.fundText} />
          <ol className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            {a.steps.map((step, i) => (
              <li key={step.title}>
                <Card className="h-full">
                  <span
                    aria-hidden="true"
                    className="flex size-11 items-center justify-center rounded-full bg-brand text-lg font-bold text-white"
                  >
                    {localizeDigits(String(i + 1), locale)}
                  </span>
                  <h3 className="mt-4 text-base font-semibold">{step.title}</h3>
                  <p className="mt-2 text-sm leading-relaxed">{step.text}</p>
                </Card>
              </li>
            ))}
          </ol>
          <p className="mx-auto mt-10 max-w-2xl text-center text-sm leading-relaxed">
            {a.transparencyText}
          </p>
        </Container>
      </section>

      {/* Contact block */}
      <section className="bg-surface py-16 sm:py-24">
        <Container className="grid items-start gap-10 lg:grid-cols-2 lg:gap-16">
          <div>
            <p className="eyebrow mb-2">{a.contactEyebrow}</p>
            <h2 className="text-2xl font-bold sm:text-3xl">{a.contactTitle}</h2>
            <p className="mt-3">{a.contactText}</p>
            {contactRows.length > 0 && <ContactList lines={contactRows} className="mt-8" />}
          </div>
          <ContactForm />
        </Container>
      </section>
    </>
  );
}
