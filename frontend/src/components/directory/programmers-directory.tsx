"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useMemo, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useDistricts } from "@/components/auth/use-districts";
import { useProfileOptions } from "@/components/profile/profile-shared";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Select } from "@/components/ui/select";
import { apiFetch, type ApiResult, type DirectoryFilters, type Paginated, type PublicProgrammer } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits, optionName } from "@/lib/format";
import { ProgrammerCard, ProgrammerCardSkeleton } from "./programmer-card";

// Same choices as the 5a API (PublicProgrammerController).
const EXPERIENCES = ["0-5", "6-10", "10+"] as const;
const EMPLOYMENTS = ["running_job", "jobless"] as const;
const SORTS = ["newest", "name", "experience"] as const;
const SEARCH_DELAY_MS = 400;

type Params = {
  q: string;
  employment_status: string;
  experience: string;
  district: string;
  machine: string;
  sort: string;
  page: number;
};

/** URL → filters (unknown values are ignored, so an old/edited link never breaks the page). */
function readParams(sp: URLSearchParams): Params {
  const pick = (key: string, allowed: readonly string[]) => {
    const v = sp.get(key) ?? "";
    return allowed.includes(v) ? v : "";
  };
  const page = Number(sp.get("page"));
  return {
    q: (sp.get("q") ?? "").trim().slice(0, 100),
    employment_status: pick("employment_status", EMPLOYMENTS),
    experience: pick("experience", EXPERIENCES),
    district: (sp.get("district") ?? "").slice(0, 50),
    machine: (sp.get("machine") ?? "").slice(0, 100),
    sort: pick("sort", SORTS),
    page: Number.isInteger(page) && page > 1 ? page : 1,
  };
}

/** Filters → query string (empty values and page 1 left out — short, shareable links). */
function toQuery(p: Partial<Params>): string {
  const out = new URLSearchParams();
  for (const [key, value] of Object.entries(p)) {
    if (value && !(key === "page" && value === 1)) out.set(key, String(value));
  }
  return out.toString();
}

/**
 * Public Programmers directory (programmers screenshot, MASTER_SPEC §21). All filters live in the URL,
 * so links can be shared and the back button works. Loaded in the browser so a logged-in member
 * also sees "members only" workplaces.
 */
export function ProgrammersDirectory() {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.directory;
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const params = useMemo(() => readParams(new URLSearchParams(searchParams.toString())), [searchParams]);
  const query = toQuery(params);
  const options = useProfileOptions();
  const { districts } = useDistricts();
  const digits = (n: number) => localizeDigits(String(n), locale);
  const listTop = useRef<HTMLDivElement>(null);

  function go(changes: Partial<Params>, { replace = false } = {}) {
    // Start from the address bar (always the latest, even inside the delayed search),
    // and any filter change starts again at page 1.
    const latest = readParams(new URLSearchParams(window.location.search));
    const next = toQuery({ ...latest, page: 1, ...changes });
    const url = next ? `${pathname}?${next}` : pathname;
    if (replace) router.replace(url, { scroll: false });
    else router.push(url, { scroll: false });
  }

  // Search box: typed text, sent to the URL when typing stops. Follows the URL on back/forward.
  const [search, setSearch] = useState(params.q);
  const [shownQ, setShownQ] = useState(params.q);
  if (shownQ !== params.q) {
    setShownQ(params.q);
    if (search.trim() !== params.q) setSearch(params.q);
  }
  useEffect(() => {
    const typed = search.trim();
    if (typed === params.q) return;
    const id = setTimeout(() => go({ q: typed }, { replace: true }), SEARCH_DELAY_MS);
    return () => clearTimeout(id);
    // eslint-disable-next-line react-hooks/exhaustive-deps -- only re-run when the text changes
  }, [search]);

  const [moreOpen, setMoreOpen] = useState(Boolean(params.district || params.machine));

  // The list. `result.key` tells which query it belongs to (loading = no result for this query yet).
  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${query}#${reloadKey}`;
  const [result, setResult] = useState<{ key: string; res: ApiResult<Paginated<PublicProgrammer>> } | null>(null);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<PublicProgrammer>>(`/public/programmers${query ? `?${query}` : ""}`).then((res) => {
      if (!cancelled) setResult({ key: requestKey, res });
    });
    return () => {
      cancelled = true;
    };
  }, [query, requestKey]);
  const current = result?.key === requestKey ? result.res : null;

  // Dropdown choices (only values used by listed programmers).
  const [filters, setFilters] = useState<DirectoryFilters | null>(null);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: DirectoryFilters }>("/public/programmers/filters").then((res) => {
      if (!cancelled && res.ok) setFilters(res.data.data);
    });
    return () => {
      cancelled = true;
    };
  }, []);

  const districtName = (key: string) => {
    const found = districts?.find((x) => x.key === key) ?? filters?.districts.find((x) => x.key === key);
    return found ? (locale === "bn" ? found.name_bn : found.name_en) : key;
  };
  // Machine filter = a list key (Phase 11d-1). A key not in the list (hidden item, old link) stays selectable.
  const machineValue = params.machine;
  const anyFilter = Boolean(params.q || params.employment_status || params.experience || params.district || params.machine);
  const clearAll = () => {
    setSearch("");
    go({ q: "", employment_status: "", experience: "", district: "", machine: "" });
  };

  const chip = (label: string, active: boolean, onClick: () => void) => (
    <button
      key={label}
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`h-9 rounded-full border px-4 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
        active ? "border-secondary bg-secondary text-white" : "border-border bg-surface text-heading hover:border-primary hover:text-primary-text"
      }`}
    >
      {label}
    </button>
  );
  const employmentLabel = (key: string) => {
    const o = options?.employment_statuses.find((x) => x.key === key);
    return o ? (locale === "bn" ? o.name_bn : o.name_en) : key === "running_job" ? "Running Job" : "Jobless";
  };
  const experienceLabels: Record<(typeof EXPERIENCES)[number], string> = { "0-5": d.years05, "6-10": d.years610, "10+": d.years10plus };

  return (
    <div className="space-y-6">
      {/* Search + filters panel (overlaps the hero) */}
      <Card className="relative -mt-16 p-4 shadow-panel! sm:p-6">
        <form role="search" onSubmit={(e) => e.preventDefault()}>
          <label htmlFor="directory-search" className="sr-only">
            {d.searchLabel}
          </label>
          <div className="relative">
            <Icon name="search" className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted" />
            <input
              id="directory-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={d.searchPlaceholder}
              maxLength={100}
              autoComplete="off"
              className="h-12 w-full rounded-full border border-field-border bg-field pr-4 pl-12 text-base text-foreground focus:bg-surface focus:outline-2 focus:outline-offset-0 focus:outline-primary"
            />
          </div>
        </form>

        <div role="group" aria-label={d.filtersLabel} className="mt-4 flex flex-wrap items-center gap-2">
          {chip(d.all, !params.employment_status && !params.experience, () => go({ employment_status: "", experience: "" }))}
          {EMPLOYMENTS.map((e) =>
            chip(employmentLabel(e), params.employment_status === e, () =>
              go({ employment_status: params.employment_status === e ? "" : e }),
            ),
          )}
          <span aria-hidden="true" className="mx-1 hidden h-6 w-px bg-border sm:block" />
          {EXPERIENCES.map((x) =>
            chip(experienceLabels[x], params.experience === x, () => go({ experience: params.experience === x ? "" : x })),
          )}
        </div>

        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <button
            type="button"
            aria-expanded={moreOpen}
            aria-controls="directory-more-filters"
            onClick={() => setMoreOpen((o) => !o)}
            className="inline-flex items-center gap-1.5 text-sm font-semibold text-secondary hover:text-primary-text"
          >
            {moreOpen ? d.hideFilters : d.moreFilters}
            {(params.district || params.machine) && !moreOpen && (
              <span className="rounded-full bg-secondary px-1.5 text-xs text-white">
                {digits(Number(Boolean(params.district)) + Number(Boolean(params.machine)))}
              </span>
            )}
            <Icon name="chevronDown" className={`size-4 transition ${moreOpen ? "rotate-180" : ""}`} />
          </button>
          {anyFilter && (
            <button type="button" onClick={clearAll} className="text-sm font-semibold text-muted underline-offset-2 hover:text-danger hover:underline">
              {d.clearFilters}
            </button>
          )}
        </div>

        {moreOpen && (
          <div id="directory-more-filters" className="mt-4 grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
            <Select label={d.district} value={params.district} onChange={(e) => go({ district: e.target.value })}>
              <option value="">{d.allDistricts}</option>
              {/* Keep a value from the URL selectable even if it is not in the list. */}
              {params.district && !filters?.districts.some((x) => x.key === params.district) && (
                <option value={params.district}>{districtName(params.district)}</option>
              )}
              {filters?.districts.map((x) => (
                <option key={x.key} value={x.key}>
                  {locale === "bn" ? x.name_bn : x.name_en}
                </option>
              ))}
            </Select>
            <Select label={d.machine} value={machineValue} onChange={(e) => go({ machine: e.target.value })}>
              <option value="">{d.allMachines}</option>
              {params.machine && !filters?.machines.some((m) => m.key === machineValue) && (
                <option value={params.machine}>{params.machine}</option>
              )}
              {filters?.machines.map((m) => (
                <option key={m.key} value={m.key}>
                  {optionName(m, locale)}
                </option>
              ))}
            </Select>
          </div>
        )}
      </Card>

      {/* Count + sort */}
      <div ref={listTop} className="flex scroll-mt-24 flex-wrap items-end justify-between gap-3">
        <p role="status" className="text-sm text-muted">
          {current?.ok ? fill(d.found, { n: digits(current.data.meta.total) }) : !current ? d.loading : ""}
        </p>
        <div className="flex items-center gap-2">
          <label htmlFor="directory-sort" className="text-sm text-muted">
            {d.sort}
          </label>
          <select
            id="directory-sort"
            value={params.sort || "newest"}
            onChange={(e) => go({ sort: e.target.value === "newest" ? "" : e.target.value })}
            className="h-10 rounded-full border border-field-border bg-surface px-4 text-sm font-semibold text-heading focus:outline-2 focus:outline-primary"
          >
            <option value="newest">{d.sortNewest}</option>
            <option value="name">{d.sortName}</option>
            <option value="experience">{d.sortExperience}</option>
          </select>
        </div>
      </div>

      {!current ? (
        <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" aria-busy="true">
          {Array.from({ length: 6 }, (_, i) => (
            <li key={i}>
              <ProgrammerCardSkeleton />
            </li>
          ))}
        </ul>
      ) : !current.ok ? (
        <Card className="mx-auto max-w-lg p-8 text-center">
          <Icon name="alert" className="mx-auto size-8 text-danger" />
          <p className="mt-3 text-danger">{current.status === 429 ? d.tooMany : d.loadFailed}</p>
          <div className="mt-5 flex flex-wrap justify-center gap-2">
            <Button variant="outline" onClick={() => setReloadKey((k) => k + 1)}>
              {d.retry}
            </Button>
            {anyFilter && (
              <Button variant="ghost" onClick={clearAll}>
                {d.clearFilters}
              </Button>
            )}
          </div>
        </Card>
      ) : current.data.data.length === 0 ? (
        <Card className="mx-auto max-w-lg p-8 text-center">
          <Icon name="users" className="mx-auto size-10 text-primary-text" />
          <p className="mt-3 font-heading text-lg font-semibold text-heading">{d.emptyTitle}</p>
          <p className="mt-1 text-muted">{d.emptyText}</p>
          {(anyFilter || params.page > 1) && (
            <Button variant="outline" className="mt-5" onClick={clearAll}>
              {d.clearFilters}
            </Button>
          )}
        </Card>
      ) : (
        <>
          <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
            {current.data.data.map((p) => (
              <li key={p.id}>
                <ProgrammerCard programmer={p} options={options} districtName={districtName(p.district)} />
              </li>
            ))}
          </ul>
          <Pagination
            page={current.data.meta.current_page}
            last={current.data.meta.last_page}
            hrefFor={(n) => {
              const q = toQuery({ ...params, page: n });
              return q ? `${pathname}?${q}` : pathname;
            }}
            onNavigate={() => listTop.current?.scrollIntoView({ behavior: "smooth", block: "start" })}
          />
        </>
      )}
    </div>
  );
}

/** Page numbers with "…" gaps: 1 … 4 5 6 … 12. Real links, so each page has its own URL. */
function Pagination({
  page,
  last,
  hrefFor,
  onNavigate,
}: {
  page: number;
  last: number;
  hrefFor: (n: number) => string;
  onNavigate: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const d = t.directory;
  if (last <= 1) return null;

  const pages: (number | "gap")[] = [];
  for (let n = 1; n <= last; n++) {
    if (n === 1 || n === last || Math.abs(n - page) <= 1) pages.push(n);
    else if (pages[pages.length - 1] !== "gap") pages.push("gap");
  }
  const base = "inline-flex h-10 min-w-10 items-center justify-center rounded-full px-3 text-sm font-semibold transition";
  const idle = `${base} border border-border bg-surface text-heading hover:border-primary hover:text-primary-text`;

  return (
    <nav aria-label={d.pagination} className="flex flex-wrap items-center justify-center gap-2 pt-2">
      {page > 1 && (
        <Link href={hrefFor(page - 1)} scroll={false} onClick={onNavigate} className={idle} aria-label={d.prev}>
          <Icon name="chevronLeft" className="size-4" />
        </Link>
      )}
      {pages.map((n, i) =>
        n === "gap" ? (
          <span key={`gap-${i}`} aria-hidden="true" className="px-1 text-muted">
            …
          </span>
        ) : n === page ? (
          <span key={n} aria-current="page" className={`${base} bg-secondary text-white`}>
            {localizeDigits(String(n), locale)}
          </span>
        ) : (
          <Link key={n} href={hrefFor(n)} scroll={false} onClick={onNavigate} className={idle} aria-label={fill(d.pageN, { n: localizeDigits(String(n), locale) })}>
            {localizeDigits(String(n), locale)}
          </Link>
        ),
      )}
      {page < last && (
        <Link href={hrefFor(page + 1)} scroll={false} onClick={onNavigate} className={idle} aria-label={d.next}>
          <Icon name="chevronRight" className="size-4" />
        </Link>
      )}
    </nav>
  );
}
