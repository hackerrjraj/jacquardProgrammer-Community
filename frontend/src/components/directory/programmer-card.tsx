"use client";

import Image from "next/image";
import Link from "next/link";
import { useDictionary, useLocale } from "@/i18n/client";
import { EmploymentBadge } from "@/components/profile/profile-view";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import type { CommitteeMember, ProfileOptions, PublicProgrammer } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits, optionName } from "@/lib/format";

export function InfoLine({ icon, children }: { icon: IconName; children: React.ReactNode }) {
  return (
    <li className="flex min-w-0 items-start gap-2 text-sm">
      <Icon name={icon} className="mt-0.5 size-4 shrink-0 text-secondary" />
      <span className="min-w-0 break-words">{children}</span>
    </li>
  );
}

/** One programmer in the public directory (programmers screenshot, MASTER_SPEC §21). */
export function ProgrammerCard({
  programmer: p,
  options,
  districtName,
}: {
  programmer: PublicProgrammer;
  options: ProfileOptions | null;
  districtName: string;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.directory;
  const href = `/programmers/${p.id}`;
  // Company only when the member shows it; a jobless member without one is "open to opportunities".
  const company = p.workplace || (p.employment_status === "jobless" ? d.openToWork : null);

  return (
    <Card className="flex h-full flex-col overflow-hidden p-0! transition hover:shadow-card-hover">
      {/* Full-width 4:3 photo (or gradient + initials), badge on top-left */}
      <Link href={href} tabIndex={-1} aria-hidden="true" className="relative block aspect-[4/3] bg-primary-soft">
        {p.photo_url ? (
          <Image
            src={p.photo_url}
            alt=""
            fill
            sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
            className="object-cover object-top"
          />
        ) : (
          <span className="flex size-full items-center justify-center">
            <span className="flex size-28 items-center justify-center rounded-full bg-linear-to-br from-primary to-navy font-heading text-4xl font-bold text-white shadow-sm">
              {initials(p.name)}
            </span>
          </span>
        )}
        {p.employment_status && (
          <span className="absolute top-3 left-3 flex rounded-full shadow-sm">
            <EmploymentBadge status={p.employment_status} options={options} />
          </span>
        )}
      </Link>

      <div className="flex flex-1 flex-col p-5 sm:p-6">
        <h2 className="text-lg leading-snug font-semibold break-words">
          <Link href={href} className="hover:text-primary-text">
            {p.name}
          </Link>
        </h2>
        <p className="min-h-5 text-sm text-muted">{p.designation}</p>

        {p.bio_excerpt && <p className="mt-4 line-clamp-3 text-sm">{p.bio_excerpt}</p>}

        <ul className="mt-4 space-y-2">
          {company && <InfoLine icon="building">{company}</InfoLine>}
          <InfoLine icon="clock">{fill(d.yearsExperience, { n: localizeDigits(String(p.experience_years), locale) })}</InfoLine>
          <InfoLine icon="mapPin">{districtName}</InfoLine>
        </ul>

        {p.machines.length > 0 && (
          <ul className="mt-3 flex flex-wrap gap-1.5" aria-label={t.profile.machines}>
            {p.machines.map((m) => (
              <li key={m.key} className="rounded-full bg-primary-soft px-2.5 py-0.5 text-xs font-semibold text-primary-text">
                {optionName(m, locale)}
              </li>
            ))}
          </ul>
        )}

        <div className="mt-auto pt-5">
          <ButtonLink href={href} variant="outline" className="w-full">
            {d.viewProfile}
          </ButtonLink>
        </div>
      </div>
    </Card>
  );
}

/**
 * One committee member on Home: same card as a programmer (photo / initials area, name), with the position
 * instead of the designation. Public fields only; "View Profile" only when the member has a public profile.
 */
export function CommitteeCard({ member: m }: { member: CommitteeMember }) {
  const d = useDictionary().directory;
  const href = m.profile_id ? `/programmers/${m.profile_id}` : null;
  const photo = (
    <>
      {m.photo_url ? (
        <Image
          src={m.photo_url}
          alt=""
          fill
          sizes="(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw"
          className="object-cover object-top"
        />
      ) : (
        <span className="flex size-full items-center justify-center">
          <span className="flex size-28 items-center justify-center rounded-full bg-linear-to-br from-primary to-navy font-heading text-4xl font-bold text-white shadow-sm">
            {initials(m.name)}
          </span>
        </span>
      )}
    </>
  );

  return (
    <Card className="flex h-full flex-col overflow-hidden p-0! transition hover:shadow-card-hover">
      {href ? (
        <Link href={href} tabIndex={-1} aria-hidden="true" className="relative block aspect-[4/3] bg-primary-soft">
          {photo}
        </Link>
      ) : (
        <div className="relative block aspect-[4/3] bg-primary-soft">{photo}</div>
      )}
      <div className="flex flex-1 flex-col p-5 sm:p-6">
        <h3 className="text-lg leading-snug font-semibold break-words">
          {href ? (
            <Link href={href} className="hover:text-primary-text">
              {m.name}
            </Link>
          ) : (
            m.name
          )}
        </h3>
        <p className="min-h-5 text-sm text-muted">{m.position}</p>
        {href && (
          <div className="mt-auto pt-5">
            <ButtonLink href={href} variant="outline" className="w-full">
              {d.viewProfile}
            </ButtonLink>
          </div>
        )}
      </div>
    </Card>
  );
}

/** "Md. Rahim Uddin" → "RU" (skips the "Md." prefix). */
function initials(name: string): string {
  const words = name.replace(/^md\.?\s+/i, "").split(/\s+/).filter(Boolean);
  return words
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join("");
}

/** Grey placeholder card while the list loads. */
export function ProgrammerCardSkeleton() {
  return (
    <Card aria-hidden="true" className="h-full animate-pulse overflow-hidden p-0!">
      <span className="block aspect-[4/3] bg-border" />
      <div className="p-5 sm:p-6">
        <span className="block h-5 w-3/4 rounded bg-border" />
        <span className="mt-2 block h-4 w-1/2 rounded bg-border" />
        <span className="mt-5 block h-4 w-full rounded bg-border" />
        <span className="mt-2 block h-4 w-5/6 rounded bg-border" />
        <span className="mt-5 block h-4 w-1/2 rounded bg-border" />
        <span className="mt-2 block h-4 w-2/5 rounded bg-border" />
        <span className="mt-6 block h-10 w-full rounded-full bg-border" />
      </div>
    </Card>
  );
}
