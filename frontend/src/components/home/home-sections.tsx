import Image from "next/image";
import Link from "next/link";
import { getDictionary, getLocale } from "@/i18n/server";
import type { Locale } from "@/i18n/config";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { Icon, isIconName, type IconName } from "@/components/ui/icon";
import { SectionHeading } from "@/components/ui/section-heading";
import { photoTitle } from "@/components/gallery/gallery-shared";
import { excerpt, noticeBody, noticeTitle } from "@/components/notices/notice-text";
import { HeroSlider } from "./hero-slider";
import { clampOverlay } from "./hero-overlay";
import { CommitteeCard, ProgrammerCard } from "@/components/directory/programmer-card";
import {
  apiFetch,
  type CommitteeMember,
  type District,
  type ExpertiseCard,
  type GalleryImage,
  type HeroSlide,
  type Notice,
  type Paginated,
  type ProfileOptions,
  type PublicProgrammer,
  type PublicStats,
} from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatNumber } from "@/lib/format";
import { BrandVisual } from "./brand-visual";

type Dictionary = Awaited<ReturnType<typeof getDictionary>>;

// ---- Hero + stat cards ----

/**
 * Hero (MASTER_SPEC §13): text, stat cards overlapping the bottom. With active admin slides
 * (Phase 11b-1, /dashboard/hero-slides) the slides are the full-width background of the hero (`HeroSlider`);
 * with none — or if the slides API is down — the single-column default hero shows (as since 6d-2).
 * If the stats API is down the hero still shows, without cards.
 */
export async function HomeHero() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const [statsRes, slidesRes] = await Promise.all([
    apiFetch<{ data: PublicStats }>("/public/stats", { locale }),
    apiFetch<{ data: HeroSlide[]; overlay?: number }>("/hero-slides", { locale }),
  ]);
  const stats = statsRes.ok ? statsRes.data?.data : undefined;
  const slides = slidesRes.ok ? (slidesRes.data?.data ?? []) : [];
  const overlay = clampOverlay(slidesRes.ok ? slidesRes.data?.overlay : undefined);
  const cards = stats ? statCards(stats, t, locale) : [];
  const h = t.home;
  const heroText = (
    <Container
      className={`relative z-10 pt-14 sm:pt-20 lg:pt-24 ${cards.length ? "pb-28 sm:pb-32 lg:pb-36" : "pb-16 sm:pb-20 lg:pb-24"}`}
    >
      <div className={`max-w-3xl ${slides.length > 0 ? "[&_h1]:[text-shadow:0_2px_8px_rgb(0_0_0/0.45)] [&_p]:[text-shadow:0_1px_6px_rgb(0_0_0/0.5)]" : ""}`}>
        <p className="eyebrow mb-4">{t.site.fullName}</p>
        <h1 className="text-[2rem] leading-tight font-bold text-white sm:text-5xl lg:text-[3.25rem]">
          <span className="block">{h.titleLine1}</span>
          <span className="block text-primary">{h.titleHighlight}</span>
          <span className="block">{h.titleLine2}</span>
        </h1>
        <p className="mt-5 max-w-2xl text-base text-white/75 sm:text-lg">{h.subtitle}</p>
        <div className="mt-8 flex flex-col gap-3 sm:flex-row">
          <ButtonLink href="/programmers" size="lg">
            {h.explore}
            <Icon name="arrowRight" className="size-4" />
          </ButtonLink>
          <ButtonLink href="/register" variant="outlineLight" size="lg">
            {h.join}
          </ButtonLink>
        </div>
      </div>
    </Container>
  );

  return (
    <>
      {slides.length > 0 ? (
        <HeroSlider slides={slides} reserveBottom={cards.length > 0} overlay={overlay}>
        {heroText}
        </HeroSlider>
      ) : (
        <section className="bg-hero text-white">
        {heroText}
        </section>
      )}

      {cards.length > 0 && (
        <Container className="relative z-10 -mt-14 sm:-mt-16">
          <dl aria-label={h.stats.label} className="grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
            {cards.map((card) => (
              <Card key={card.label} className="flex flex-col p-4! sm:p-5!">
                <span className="flex size-9 items-center justify-center rounded-control bg-primary-soft text-primary-text">
                  <Icon name={card.icon} className="size-5" />
                </span>
                {/* Value shown above its label (order keeps dt before dd in the markup) */}
                <dt className="order-3 mt-0.5 text-sm text-muted">{card.label}</dt>
                <dd className="order-2 mt-3 font-heading text-2xl font-bold break-words text-heading sm:text-3xl">
                  {card.value}
                </dd>
              </Card>
            ))}
          </dl>
        </Container>
      )}
    </>
  );
}

/**
 * The stat cards, in screenshot order. A card whose value is 0 or null is left out —
 * so the money cards (null until Phases 7–8) appear by themselves once real data exists.
 */
function statCards(s: PublicStats, t: Dictionary, locale: Locale) {
  const l = t.home.stats;
  const count = (n: number) => formatNumber(n, locale);
  const all: { icon: IconName; raw: number | null; label: string; format: (n: number) => string }[] = [
    { icon: "users", raw: s.programmers, label: l.programmers, format: count },
    { icon: "building", raw: s.companies, label: l.companies, format: count },
    {
      icon: "trendingUp",
      raw: s.max_experience_years,
      label: l.experience,
      format: (n) => fill(l.experienceValue, { n: count(n) }),
    },
    { icon: "mapPin", raw: s.locations, label: l.locations, format: count },
  ];
  return all
    .filter((c): c is typeof c & { raw: number } => typeof c.raw === "number" && c.raw > 0)
    .map((c) => ({ icon: c.icon, label: c.label, value: c.format(c.raw) }));
}

/** Same-size navy block while slides + stats load (avoids a text flash). */
export function HeroPlaceholder() {
  return <div aria-hidden="true" className="min-h-[38rem] bg-hero sm:min-h-[36rem] lg:min-h-[34rem]" />;
}

// ---- About block ----

export async function AboutBlock() {
  const t = await getDictionary();
  const a = t.home.about;
  return (
    <section className="bg-surface py-16 sm:py-24">
      <Container className="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <BrandVisual caption={t.site.fullName} />
        <div>
          <p className="eyebrow mb-2">{a.eyebrow}</p>
          <h2 className="text-2xl font-bold sm:text-3xl">{a.title}</h2>
          <p className="mt-4 leading-relaxed">{a.text}</p>
          <ButtonLink href="/about" variant="secondary" className="mt-7">
            {a.button}
            <Icon name="arrowRight" className="size-4" />
          </ButtonLink>
        </div>
      </Container>
    </section>
  );
}

// ---- Our Committee ----

const HOME_COMMITTEE_LIMIT = 6;

/**
 * Up to 6 committee members (the About page's public list, same order) as full-size programmer-style cards.
 * More than 6 → "See full committee" (About page). None / API down → section hidden.
 */
export async function HomeCommittee() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const res = await apiFetch<{ data: CommitteeMember[] }>("/public/committee", { locale });
  const all = res.ok ? (res.data?.data ?? []) : [];
  if (all.length === 0) return null;
  const c = t.home.committee;

  return (
    <section className="bg-background py-16 sm:py-24">
      <Container>
        <SectionHeading eyebrow={c.eyebrow} title={c.title} subtitle={c.subtitle} />
        <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {all.slice(0, HOME_COMMITTEE_LIMIT).map((member, i) => (
            <li key={i}>
              <CommitteeCard member={member} />
            </li>
          ))}
        </ul>
        {all.length > HOME_COMMITTEE_LIMIT && (
          <div className="mt-10 text-center">
            <ButtonLink href="/about" variant="outline">
              {c.button}
              <Icon name="arrowRight" className="size-4" />
            </ButtonLink>
          </div>
        )}
      </Container>
    </section>
  );
}

// ---- Our Programmers ----

/**
 * Up to 6 listed programmers (featured first, then the newest), shown with the directory's card in the
 * directory's grid (3 / 2 / 1 per row). Loaded on the server without a login cookie,
 * so cards show public fields only. None / API down → section hidden.
 */
export async function HomeProgrammers() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const [res, districtsRes, optionsRes] = await Promise.all([
    apiFetch<{ data: PublicProgrammer[] }>("/public/programmers/home", { locale }),
    apiFetch<{ data: District[] }>("/districts", { locale }),
    apiFetch<{ data: ProfileOptions }>("/profile-options", { locale }),
  ]);
  const programmers = res.ok ? (res.data?.data ?? []) : [];
  if (programmers.length === 0) return null;
  const districts = districtsRes.ok ? (districtsRes.data?.data ?? []) : [];
  const options = optionsRes.ok ? (optionsRes.data?.data ?? null) : null;
  const districtNames = Object.fromEntries(districts.map((d) => [d.key, locale === "bn" ? d.name_bn : d.name_en]));
  const p = t.home.programmers;

  return (
    <section className="overflow-hidden bg-background py-16 sm:py-24">
      <Container>
        <SectionHeading eyebrow={p.eyebrow} title={p.title} subtitle={p.subtitle} />
      </Container>
      <Container>
        <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {programmers.map((pr) => (
            <li key={pr.id}>
              <ProgrammerCard programmer={pr} options={options} districtName={districtNames[pr.district] ?? pr.district} />
            </li>
          ))}
        </ul>
      </Container>
      <div className="mt-10 text-center">
        <ButtonLink href="/programmers" variant="outline">
          {p.button}
          <Icon name="arrowRight" className="size-4" />
        </ButtonLink>
      </div>
    </section>
  );
}

// ---- Expertise ----

function DarkSectionHeading({ eyebrow, title }: { eyebrow: string; title: string }) {
  return (
    <div className="mx-auto max-w-2xl text-center">
      <p className="eyebrow mb-2">{eyebrow}</p>
      <h2 className="text-2xl font-bold text-white sm:text-3xl">{title}</h2>
    </div>
  );
}

/** Expertise section from the admin-managed cards. No cards / API down → section hidden. */
export async function Expertise() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const res = await apiFetch<{ data: ExpertiseCard[] }>("/public/expertise-cards", { locale });
  const cards = res.ok ? (res.data?.data ?? []) : [];
  if (cards.length === 0) return null;

  return (
    <section className="bg-hero py-16 text-white sm:py-24">
      <Container>
        <DarkSectionHeading eyebrow={t.home.expertise.eyebrow} title={t.home.expertise.title} />
        <ExpertiseGrid cards={cards} locale={locale} />
      </Container>
    </section>
  );
}

/** Loading state: the dark section with 3 grey card shapes. */
export async function ExpertiseSkeleton() {
  const t = await getDictionary();
  return (
    <section className="bg-hero py-16 sm:py-24" aria-busy="true">
      <Container>
        <DarkSectionHeading eyebrow={t.home.expertise.eyebrow} title={t.home.expertise.title} />
        <p className="sr-only">{t.home.expertise.loading}</p>
        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="min-h-64 animate-pulse rounded-card bg-white/5 ring-1 ring-white/10" />
          ))}
        </div>
      </Container>
    </section>
  );
}

/** English falls back to Bengali when empty (the API does this too). */
function cardText(card: ExpertiseCard, locale: Locale) {
  return locale === "en"
    ? { title: card.title_en || card.title_bn, description: card.description_en || card.description_bn }
    : { title: card.title_bn, description: card.description_bn };
}

function ExpertiseGrid({ cards, locale }: { cards: ExpertiseCard[]; locale: Locale }) {
  return (
    <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      {cards.map((card) => {
        const { title, description } = cardText(card, locale);
        const icon: IconName = isIconName(card.icon) ? card.icon : "chip";
        return (
          <li
            key={card.id}
            className={`group relative isolate flex min-h-64 flex-col overflow-hidden rounded-card p-5 shadow-image ring-1 ring-white/10 sm:p-6 ${card.image_url ? "justify-end" : "bg-navy-band"}`}
          >
            {card.image_url ? (
              <>
                <Image
                  src={card.image_url}
                  alt=""
                  fill
                  unoptimized
                  sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
                  className="-z-10 object-cover transition duration-500 group-hover:scale-105"
                />
                <div className="absolute inset-0 -z-10 bg-linear-to-t from-navy/95 via-navy/45 to-navy/5" />
              </>
            ) : (
              <>
                {/* No picture → the card's icon (large faint copy as decoration) */}
                <Icon name={icon} className="absolute -top-6 -right-6 -z-10 size-40 text-white/5" />
                <span className="flex size-12 items-center justify-center rounded-control bg-brand text-white shadow-sm">
                  <Icon name={icon} className="size-6" />
                </span>
              </>
            )}
            <h3
              className={`font-heading text-lg font-semibold break-words text-white uppercase ${card.image_url ? "" : "mt-auto pt-8"}`}
            >
              {title}
            </h3>
            <p className="mt-1.5 text-sm leading-relaxed break-words text-white/80">{description}</p>
          </li>
        );
      })}
    </ul>
  );
}

// ---- Community Gallery preview ----

const GALLERY_PREVIEW = 6;

/** Newest published photos (admin order). No photos / API down → section hidden. */
export async function GalleryPreview() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const res = await apiFetch<Paginated<GalleryImage>>("/public/gallery", { locale });
  const photos = res.ok ? (res.data?.data ?? []).slice(0, GALLERY_PREVIEW) : [];
  if (photos.length === 0) return null;
  const g = t.home.gallery;

  return (
    <section className="bg-surface py-16 sm:py-24">
      <Container>
        <SectionHeading eyebrow={g.eyebrow} title={g.title} />
        <ul className="mt-10 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-5">
          {photos.map((photo, i) => {
            const title = photoTitle(photo, locale);
            return (
              <li key={photo.id}>
                <Link
                  href="/gallery"
                  className="group relative block aspect-[4/3] overflow-hidden rounded-card bg-background shadow-card focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                >
                  <Image
                    src={photo.thumb_url}
                    alt={title ?? fill(g.photoAlt, { n: formatNumber(i + 1, locale) })}
                    fill
                    unoptimized
                    sizes="(min-width: 640px) 33vw, 50vw"
                    className="object-cover transition duration-300 group-hover:scale-105"
                  />
                </Link>
              </li>
            );
          })}
        </ul>
        <div className="mt-10 text-center">
          <ButtonLink href="/gallery" variant="outline">
            {g.button}
            <Icon name="arrowRight" className="size-4" />
          </ButtonLink>
        </div>
      </Container>
    </section>
  );
}

// ---- Latest notices ----

const NOTICES_PREVIEW = 3;

/** Newest public notices (pinned first, as the API sends them). None / API down → section hidden. */
export async function LatestNotices() {
  const [t, locale] = await Promise.all([getDictionary(), getLocale()]);
  const res = await apiFetch<Paginated<Notice>>("/public/notices", { locale });
  const notices = res.ok ? (res.data?.data ?? []).slice(0, NOTICES_PREVIEW) : [];
  if (notices.length === 0) return null;
  const n = t.home.notices;

  return (
    <section className="bg-background py-16 sm:py-24">
      <Container>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <SectionHeading eyebrow={n.eyebrow} title={n.title} align="left" />
          <ButtonLink href="/notices" variant="outline" className="self-start sm:self-auto">
            {n.button}
            <Icon name="arrowRight" className="size-4" />
          </ButtonLink>
        </div>
        <ul className="mt-10 grid gap-5 md:grid-cols-3">
          {notices.map((notice) => (
            <li key={notice.id}>
              <Link
                href={`/notices/${notice.id}`}
                className={`flex h-full flex-col rounded-card border bg-surface p-5 shadow-card transition hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${notice.is_pinned ? "border-primary/50" : "border-border"}`}
              >
                <span className="flex items-center gap-1.5 text-xs font-semibold text-primary-text">
                  <Icon name={notice.is_pinned ? "pin" : "calendar"} className="size-4" />
                  <time dateTime={notice.published_at ?? undefined}>{formatDate(notice.published_at, locale)}</time>
                </span>
                <h3 className="mt-3 line-clamp-2 text-base font-semibold break-words">{noticeTitle(notice, locale)}</h3>
                <p className="mt-2 line-clamp-2 text-sm break-words">{excerpt(noticeBody(notice, locale), 140)}</p>
                <span className="mt-auto pt-4 text-sm font-semibold text-secondary">{n.readMore} →</span>
              </Link>
            </li>
          ))}
        </ul>
      </Container>
    </section>
  );
}

// ---- Call to action ----

export async function CallToAction() {
  const t = await getDictionary();
  return (
    <section className="bg-surface py-16 sm:py-24">
      <Container>
        <div className="rounded-card bg-navy-band px-6 py-12 text-center text-white shadow-image sm:px-12 sm:py-16">
          <h2 className="mx-auto max-w-2xl text-2xl font-bold text-white sm:text-3xl">{t.home.cta.title}</h2>
          <p className="mx-auto mt-3 max-w-2xl text-white/80">{t.home.cta.text}</p>
          <ButtonLink href="/register" size="lg" className="mt-8">
            {t.home.cta.button}
          </ButtonLink>
        </div>
      </Container>
    </section>
  );
}
