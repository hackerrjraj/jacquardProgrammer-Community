"use client";

import Image from "next/image";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { useDistricts } from "@/components/auth/use-districts";
import { Avatar, EmploymentBadge, PortfolioCard } from "@/components/profile/profile-view";
import { optionLabel, useProfileOptions } from "@/components/profile/profile-shared";
import { MessageButton } from "@/components/chat/message-button";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type ProfileOptions, type PublicProgrammerProfile } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits, optionName, optionNames } from "@/lib/format";

type PortfolioEntry = PublicProgrammerProfile["portfolio"][number];

function Meta({ icon, label, value }: { icon: IconName; label: string; value: string }) {
  return (
    <li className="flex min-w-0 items-start gap-2 text-sm">
      <Icon name={icon} className="mt-0.5 size-4 shrink-0 text-secondary" />
      <span className="min-w-0 break-words">
        <span className="text-muted">{label}: </span>
        <span className="text-heading">{value}</span>
      </span>
    </li>
  );
}

/**
 * Public programmer profile (programmer-profile screenshot, MASTER_SPEC §22).
 * `initial` comes from the server (no login cookie). A logged-in approved member's browser asks
 * once more, so "members only" mobile / email / workplace appear for them.
 */
export function PublicProfile({ initial }: { initial: PublicProgrammerProfile }) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.directory;
  const p = t.profile;
  const { user } = useAuth();
  const options = useProfileOptions();
  const { districts } = useDistricts();
  const [profile, setProfile] = useState(initial);
  const [contactOpen, setContactOpen] = useState(false);
  const [lightbox, setLightbox] = useState<number | null>(null);
  const digits = (n: number | string) => localizeDigits(String(n), locale);

  const isMember = user?.status === "approved";
  useEffect(() => {
    if (!isMember) return;
    let cancelled = false;
    void apiFetch<{ data: PublicProgrammerProfile }>(`/public/programmers/${initial.id}`).then((res) => {
      if (!cancelled && res.ok) setProfile(res.data.data);
    });
    return () => {
      cancelled = true;
    };
  }, [isMember, initial.id]);

  const district = districts?.find((x) => x.key === profile.district);
  const districtName = district ? (locale === "bn" ? district.name_bn : district.name_en) : profile.district;

  return (
    <>
      {/* Navy band behind the top of the header card */}
      <div className="bg-hero h-28 sm:h-36" />
      <div className="mx-auto w-full max-w-[1600px] space-y-8 px-4 pb-12 sm:px-8 sm:pb-16 lg:px-12">
        {/* Header card */}
        <Card className="relative -mt-20 p-5 shadow-panel! sm:-mt-24 sm:p-8">
          <div className="flex flex-col gap-6 sm:flex-row sm:items-start">
            <Avatar
              name={profile.name}
              url={profile.photo_url}
              className="size-32 ring-4 ring-background sm:size-40"
              rounded="rounded-2xl"
            />
            <div className="min-w-0 flex-1">
              <EmploymentBadge status={profile.employment_status} options={options} />
              <h1 className="mt-2 text-2xl font-bold break-words sm:text-4xl">{profile.name}</h1>
              {profile.designation && <p className="mt-1 text-muted">{profile.designation}</p>}
              <ul className="mt-5 grid gap-x-8 gap-y-2.5 md:grid-cols-2">
                {profile.workplace && <Meta icon="building" label={p.currentCompany} value={profile.workplace} />}
                <Meta icon="clock" label={p.experience} value={fill(p.years, { n: digits(profile.experience_years) })} />
                <Meta icon="mapPin" label={p.location} value={districtName} />
                {profile.machines.length > 0 && <Meta icon="chip" label={p.machines} value={optionNames(profile.machines, locale)} />}
              </ul>
              <div className="mt-6 flex flex-wrap gap-2">
                <Button onClick={() => setContactOpen(true)}>
                  <Icon name="mail" className="size-4" />
                  {d.contact}
                </Button>
                <MessageButton userId={profile.id} accepts={profile.accepts_messages} />
                <ButtonLink href="/programmers" variant="outline">
                  <Icon name="arrowLeft" className="size-4" />
                  {d.back}
                </ButtonLink>
              </div>
            </div>
          </div>
        </Card>

        {/* Professional profile + experience timeline */}
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="p-6 sm:p-7 lg:col-span-2">
            <h2 className="text-xl font-semibold">{p.professionalProfile}</h2>
            <p className="mt-3 leading-relaxed whitespace-pre-line">{profile.bio || <span className="text-muted">{p.noBio}</span>}</p>
          </Card>
          <Card className="p-6 sm:p-7">
            <h2 className="text-xl font-semibold">{p.experienceTitle}</h2>
            {profile.work_history.length === 0 ? (
              <p className="mt-3 text-muted">{p.noExperience}</p>
            ) : (
              <ol className="mt-5 space-y-5 border-l-2 border-primary-soft pl-5">
                {profile.work_history.map((w, i) => (
                  <li key={i} className="relative">
                    <span aria-hidden="true" className="absolute top-1 -left-[1.6rem] size-3 rounded-full bg-primary ring-4 ring-surface" />
                    <p className="text-xs font-semibold tracking-(--caps-tracking) text-secondary uppercase">
                      {digits(w.start_year)} — {w.end_year === "present" ? p.present : digits(w.end_year)}
                    </p>
                    <p className="mt-1 font-semibold text-heading">{w.company}</p>
                    <p className="text-sm text-muted">{w.position}</p>
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </div>

        <TileSection title={p.machineExperience} items={profile.machines.map((m) => optionName(m, locale))} icon="chip" />
        <TileSection title={p.softwareSkills} items={profile.skills.map((s) => optionName(s, locale))} icon="fileText" />

        {profile.portfolio.length > 0 && (
          <section>
            <h2 className="text-xl font-semibold">{p.portfolioTitle}</h2>
            <ul className="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
              {profile.portfolio.map((item, i) => (
                <li key={item.id}>
                  <button
                    type="button"
                    onClick={() => setLightbox(i)}
                    aria-label={fill(d.viewImage, { title: item.title })}
                    className="block h-full w-full rounded-card text-left transition hover:-translate-y-0.5 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <PortfolioCard item={{ ...item, sort_order: i }} options={options} />
                  </button>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>

      {contactOpen && <ContactBox profile={profile} loggedIn={Boolean(user)} onClose={() => setContactOpen(false)} />}
      {lightbox !== null && (
        <Lightbox items={profile.portfolio} index={lightbox} options={options} onChange={setLightbox} onClose={() => setLightbox(null)} />
      )}
    </>
  );
}

function TileSection({ title, items, icon }: { title: string; items: string[]; icon: IconName }) {
  if (items.length === 0) return null;
  return (
    <section>
      <h2 className="text-xl font-semibold">{title}</h2>
      <ul className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {items.map((item) => (
          <li
            key={item}
            className="flex flex-col items-center gap-2 rounded-card border border-border bg-surface px-3 py-4 text-center text-sm font-semibold break-words text-heading shadow-card"
          >
            <Icon name={icon} className="size-5 text-primary-text" />
            {item}
          </li>
        ))}
      </ul>
    </section>
  );
}

/** "Contact Programmer": mobile / email only if the API sent them (the member's privacy settings, §20). */
function ContactBox({ profile, loggedIn, onClose }: { profile: PublicProgrammerProfile; loggedIn: boolean; onClose: () => void }) {
  const t = useDictionary();
  const d = t.directory;
  const hasAny = Boolean(profile.phone || profile.email);

  return (
    <Modal title={fill(d.contactTitle, { name: profile.name })} onClose={onClose}>
      {hasAny ? (
        <ul className="space-y-3">
          {profile.phone && (
            <li className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-border p-3">
              <span className="flex items-center gap-2">
                <Icon name="phone" className="size-4 text-secondary" />
                <span className="font-semibold text-heading">{profile.phone}</span>
              </span>
              <a href={`tel:${profile.phone}`} className="text-sm font-semibold text-primary-text hover:underline">
                {d.call}
              </a>
            </li>
          )}
          {profile.email && (
            <li className="flex flex-wrap items-center justify-between gap-3 rounded-control border border-border p-3">
              <span className="flex min-w-0 items-center gap-2">
                <Icon name="mail" className="size-4 shrink-0 text-secondary" />
                <span className="font-semibold break-all text-heading">{profile.email}</span>
              </span>
              <a href={`mailto:${profile.email}`} className="text-sm font-semibold text-primary-text hover:underline">
                {d.sendEmail}
              </a>
            </li>
          )}
        </ul>
      ) : (
        <p>{d.contactNone}</p>
      )}
      {!loggedIn && (
        <p className="mt-4 text-sm text-muted">
          {d.contactMembersHint}{" "}
          <Link href={`/login?next=${encodeURIComponent(`/programmers/${profile.id}`)}`} className="font-semibold text-primary-text hover:underline">
            {t.nav.login}
          </Link>
        </p>
      )}
      <div className="mt-5 flex justify-end">
        <Button variant="outline" onClick={onClose}>
          {d.close}
        </Button>
      </div>
    </Modal>
  );
}

/** Simple portfolio lightbox: big image + text, ←/→ keys and buttons, Esc / backdrop closes. */
function Lightbox({
  items,
  index,
  options,
  onChange,
  onClose,
}: {
  items: PortfolioEntry[];
  index: number;
  options: ProfileOptions | null;
  onChange: (i: number) => void;
  onClose: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.directory;
  const ref = useRef<HTMLDialogElement>(null);
  const item = items[index];
  const count = items.length;

  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  const move = (step: number) => onChange((index + step + count) % count);

  return (
    <dialog
      ref={ref}
      aria-label={item.title}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => {
        if (e.target === ref.current) onClose();
      }}
      onKeyDown={(e) => {
        if (count > 1 && e.key === "ArrowLeft") move(-1);
        if (count > 1 && e.key === "ArrowRight") move(1);
      }}
      className="m-auto w-[calc(100%-2rem)] max-w-4xl overflow-hidden rounded-card bg-surface p-0 text-text shadow-panel backdrop:bg-navy/85"
    >
      <div className="relative aspect-[4/3] max-h-[70vh] w-full bg-navy">
        <Image src={item.image_url} alt={item.title} fill sizes="(min-width: 896px) 896px, 100vw" className="object-contain" />
        <button
          type="button"
          onClick={onClose}
          aria-label={d.close}
          className="absolute top-3 right-3 flex size-10 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy"
        >
          <Icon name="close" className="size-5" />
        </button>
        {count > 1 && (
          <>
            <button
              type="button"
              onClick={() => move(-1)}
              aria-label={d.prevImage}
              className="absolute top-1/2 left-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy"
            >
              <Icon name="chevronLeft" className="size-5" />
            </button>
            <button
              type="button"
              onClick={() => move(1)}
              aria-label={d.nextImage}
              className="absolute top-1/2 right-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy"
            >
              <Icon name="chevronRight" className="size-5" />
            </button>
          </>
        )}
      </div>
      <div className="flex items-start justify-between gap-4 p-5">
        <div className="min-w-0">
          <p className="eyebrow">{optionLabel(options?.portfolio_categories, item.category, locale)}</p>
          <p className="mt-1 font-heading text-lg font-semibold break-words text-heading">{item.title}</p>
          {item.description && <p className="mt-1 text-sm">{item.description}</p>}
        </div>
        {count > 1 && (
          <p className="shrink-0 text-sm text-muted">
            {localizeDigits(fill(d.imageCount, { n: index + 1, total: count }), locale)}
          </p>
        )}
      </div>
    </dialog>
  );
}
