"use client";

import Image from "next/image";
import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useSiteImage } from "@/components/layout/site-images-provider";
import { StatusBadge } from "@/components/auth/account-view";
import { useDistricts } from "@/components/auth/use-districts";
import { Alert } from "@/components/ui/alert";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { apiDownload, type EmploymentStatus, type MemberDocument, type Profile, type ProfileOptions } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { localizeDigits, optionName, optionNames, withOther } from "@/lib/format";
import { formatSize, optionLabel } from "./profile-shared";

/** Round/rounded photo, or the first letter of the name. */
export function Avatar({
  name,
  url,
  className = "size-28 sm:size-32",
  rounded = "rounded-2xl",
}: {
  name: string;
  url: string | null;
  className?: string;
  rounded?: string;
}) {
  // Members without their own photo get the admin's default profile picture (Site Content → Images), else the initial.
  const fallback = useSiteImage("default_avatar");
  const shown = url ?? fallback;
  return (
    <span className={`relative block shrink-0 overflow-hidden bg-primary-soft ${rounded} ${className}`}>
      {shown ? (
        <Image src={shown} alt="" fill sizes="128px" className="object-cover" />
      ) : (
        <span aria-hidden="true" className="flex size-full items-center justify-center font-heading text-3xl font-bold text-primary-text">
          {name.trim().charAt(0).toUpperCase()}
        </span>
      )}
    </span>
  );
}

/** "Running job" (green) / "Jobless" (red) pill with a dot. */
export function EmploymentBadge({ status, options }: { status: EmploymentStatus | null; options: ProfileOptions | null }) {
  const locale = useLocale();
  if (!status) return null;
  const tone = status === "running_job" ? "bg-success-soft text-success" : "bg-danger-soft text-danger";
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ${tone}`}>
      <span aria-hidden="true" className="size-1.5 rounded-full bg-current" />
      {optionLabel(options?.employment_statuses, status, locale)}
    </span>
  );
}

function MetaLine({ icon, label, value }: { icon: IconName; label: string; value: React.ReactNode }) {
  return (
    <li className="flex min-w-0 items-start gap-2 text-sm">
      <Icon name={icon} className="mt-0.5 size-4 shrink-0 text-secondary" />
      <span className="min-w-0 break-words">
        <span className="text-muted">{label}: </span>
        {value}
      </span>
    </li>
  );
}

/**
 * A member's profile, laid out like the programmer-profile screenshot (MASTER_SPEC §8a, §22):
 * header card, professional profile, experience timeline, machine chips, portfolio grid,
 * and (dashboard only) the private documents.
 */
export function ProfileView({
  profile,
  options,
  actions,
  belowHeader,
  showDocuments = false,
}: {
  profile: Profile;
  options: ProfileOptions | null;
  /** Buttons under the header (e.g. Back, Edit name/email) */
  actions?: React.ReactNode;
  /** Shown right under the header card (e.g. the member's payment history on the Programmers List detail page) */
  belowHeader?: React.ReactNode;
  showDocuments?: boolean;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const p = t.profile;
  const { districts } = useDistricts();
  const district = districts?.find((d) => d.key === profile.district);
  const districtName = district ? (locale === "bn" ? district.name_bn : district.name_en) : profile.district;
  const none = <span className="text-muted">{p.notProvided}</span>;
  const digits = (n: number | string) => localizeDigits(String(n), locale);
  const machines = withOther(profile.machines, profile.other_machine);
  const skills = withOther(profile.skills, profile.other_skill);

  return (
    <div className="space-y-6">
      {/* Header card */}
      <Card className="p-5 shadow-panel! sm:p-8">
        <div className="flex flex-col gap-5 sm:flex-row sm:items-start">
          <Avatar name={profile.name} url={profile.photo_url} />
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <EmploymentBadge status={profile.employment_status} options={options} />
              <StatusBadge status={profile.status} />
            </div>
            <h2 className="mt-2 text-2xl font-bold break-words sm:text-3xl">{profile.name}</h2>
            {profile.designation && <p className="mt-1 text-muted">{profile.designation}</p>}
            <ul className="mt-4 grid gap-x-6 gap-y-2 md:grid-cols-2">
              <MetaLine icon="building" label={p.currentCompany} value={profile.workplace ?? none} />
              <MetaLine
                icon="clock"
                label={p.experience}
                value={profile.experience_years === null ? none : fill(p.years, { n: digits(profile.experience_years) })}
              />
              <MetaLine icon="mapPin" label={p.location} value={districtName ?? none} />
              <MetaLine icon="chip" label={p.machines} value={machines.length ? optionNames(machines, locale) : none} />
              <MetaLine icon="phone" label={p.phone} value={profile.phone ?? none} />
              <MetaLine icon="mail" label={p.email} value={profile.email ?? none} />
            </ul>
            {actions && <div className="mt-5 flex flex-wrap gap-2">{actions}</div>}
          </div>
        </div>
      </Card>

      {belowHeader}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="p-6 lg:col-span-2">
          <h3 className="text-lg font-semibold">{p.professionalProfile}</h3>
          <p className="mt-3 leading-relaxed whitespace-pre-line">{profile.bio || <span className="text-muted">{p.noBio}</span>}</p>
        </Card>

        <Card className="p-6">
          <h3 className="text-lg font-semibold">{p.experienceTitle}</h3>
          {profile.work_history.length === 0 ? (
            <p className="mt-3 text-muted">{p.noExperience}</p>
          ) : (
            <ol className="mt-4 space-y-5 border-l-2 border-primary-soft pl-5">
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

      <section>
        <h3 className="text-lg font-semibold">{p.machineExperience}</h3>
        <ChipList items={machines.map((m) => optionName(m, locale))} empty={p.noneYet} icon="chip" />
        <h3 className="mt-6 text-lg font-semibold">{p.softwareSkills}</h3>
        <ChipList items={skills.map((s) => optionName(s, locale))} empty={p.noneYet} icon="fileText" />
      </section>

      <section>
        <h3 className="text-lg font-semibold">{p.portfolioTitle}</h3>
        {profile.portfolio.length === 0 ? (
          <p className="mt-3 text-muted">{p.noPortfolio}</p>
        ) : (
          <ul className="mt-4 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            {profile.portfolio.map((item) => (
              <li key={item.id}>
                <PortfolioCard item={item} options={options} />
              </li>
            ))}
          </ul>
        )}
      </section>

      {showDocuments && <DocumentList documents={profile.documents} options={options} />}
    </div>
  );
}

function ChipList({ items, empty, icon }: { items: string[]; empty: string; icon: IconName }) {
  if (items.length === 0) return <p className="mt-3 text-muted">{empty}</p>;
  return (
    <ul className="mt-3 flex flex-wrap gap-3">
      {items.map((item) => (
        <li
          key={item}
          className="flex items-center gap-2 rounded-card border border-border bg-surface px-4 py-3 text-sm font-semibold text-heading shadow-card"
        >
          <Icon name={icon} className="size-4 text-primary-text" />
          <span className="tracking-(--caps-tracking) uppercase">{item}</span>
        </li>
      ))}
    </ul>
  );
}

export function PortfolioCard({
  item,
  options,
  children,
}: {
  item: Profile["portfolio"][number];
  options: ProfileOptions | null;
  children?: React.ReactNode;
}) {
  const locale = useLocale();
  return (
    <Card className="flex h-full flex-col overflow-hidden p-0!">
      <span className="relative block aspect-[4/3] bg-background">
        <Image src={item.image_url} alt={item.title} fill sizes="(min-width: 1280px) 33vw, (min-width: 640px) 50vw, 100vw" className="object-cover" />
      </span>
      <div className="flex flex-1 flex-col p-5">
        <p className="eyebrow">{optionLabel(options?.portfolio_categories, item.category, locale)}</p>
        <p className="mt-1 font-heading font-semibold text-heading">{item.title}</p>
        {item.description && <p className="mt-1 text-sm">{item.description}</p>}
        {children}
      </div>
    </Card>
  );
}

/** Private documents. Download only when the API sent a download_url (owner, admin, super_admin — not committee). */
export function DocumentList({
  documents,
  options,
  onDelete,
}: {
  documents: MemberDocument[];
  options: ProfileOptions | null;
  onDelete?: (doc: MemberDocument) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const p = t.profile;
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);

  async function download(doc: MemberDocument) {
    if (!doc.download_url) return;
    setBusy(doc.id);
    setError(null);
    const status = await apiDownload(doc.download_url, doc.original_name ?? "document");
    setBusy(null);
    if (status !== 200) setError(status === 0 ? t.auth.networkError : p.downloadFailed);
  }

  return (
    <section>
      <h3 className="text-lg font-semibold">{p.documentsTitle}</h3>
      {error && (
        <Alert className="mt-3" tone="danger">
          {error}
        </Alert>
      )}
      {documents.length === 0 ? (
        <p className="mt-3 text-muted">{p.noDocuments}</p>
      ) : (
        <ul className="mt-3 divide-y divide-border rounded-card border border-border bg-surface shadow-card">
          {documents.map((doc) => (
            <li key={doc.id} className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
              <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-icon-box text-white">
                <Icon name="fileText" className="size-5" />
              </span>
              <div className="min-w-0 flex-1">
                <p className="font-semibold break-words text-heading">{optionLabel(options?.document_types, doc.type, locale)}</p>
                <p className="text-sm break-all text-muted">
                  {[
                    doc.original_name,
                    doc.size !== undefined && formatSize(doc.size, locale),
                    doc.created_at && formatDate(doc.created_at, locale),
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              </div>
              <div className="flex shrink-0 gap-2">
                {doc.download_url && (
                  <button
                    type="button"
                    onClick={() => void download(doc)}
                    disabled={busy === doc.id}
                    className="rounded-full border border-border px-4 py-1.5 text-sm font-semibold text-secondary hover:border-primary hover:text-primary-text disabled:opacity-50"
                  >
                    {busy === doc.id ? t.auth.loading : p.download}
                  </button>
                )}
                {onDelete && (
                  <button
                    type="button"
                    onClick={() => onDelete(doc)}
                    className="rounded-full border border-danger/30 px-4 py-1.5 text-sm font-semibold text-danger hover:bg-danger-soft"
                  >
                    {p.delete}
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
