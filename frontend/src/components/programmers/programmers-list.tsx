"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { StatusBadge } from "@/components/auth/account-view";
import { RequireAuth } from "@/components/auth/require-auth";
import { useDistricts } from "@/components/auth/use-districts";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar, EmploymentBadge } from "@/components/profile/profile-view";
import { useProfileOptions } from "@/components/profile/profile-shared";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { apiFetch, type MemberListPage, type MemberListItem, type Status, type User } from "@/lib/api";
import { monthLabel } from "@/components/payments/payments-shared";
import { fill } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";

const PER_PAGE = 20;
const STATUSES: Status[] = ["approved", "pending", "suspended", "rejected"];
const MONTH_FILTERS = ["", "paid", "due"] as const;

/** /dashboard/programmers — admin, super_admin, committee (read-only), with the money columns of Phase 7d. */
export function ProgrammersListScreen() {
  return <RequireAuth roles={navItem("programmers").roles}>{(me) => <ProgrammersList me={me} />}</RequireAuth>;
}

function ProgrammersList({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const l = t.programmersList;
  const router = useRouter();
  const options = useProfileOptions();
  const { districts } = useDistricts();
  const digits = (n: number) => localizeDigits(String(n), locale);

  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<Status | "">("approved");
  const [employment, setEmployment] = useState("");
  const [district, setDistrict] = useState("");
  const [dues, setDues] = useState<"" | "with" | "without">("");
  const [sort, setSort] = useState<"newest" | "name" | "due">("newest");
  // Month filter (Phase 7f): defaults to the current month.
  const [now] = useState(() => new Date());
  const [monthNum, setMonthNum] = useState(now.getMonth() + 1);
  const [year, setYear] = useState(now.getFullYear());
  const [monthStatus, setMonthStatus] = useState<(typeof MONTH_FILTERS)[number]>("");
  const monthKey = `${year}-${String(monthNum).padStart(2, "0")}`;
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [list, setList] = useState<MemberListPage | null>(null);
  const [failed, setFailed] = useState(false);

  // Search: wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page), per_page: String(PER_PAGE) });
    if (status) params.set("status", status);
    if (employment) params.set("employment_status", employment);
    if (district) params.set("district", district);
    if (query) params.set("search", query);
    if (dues) params.set("dues", dues);
    if (sort !== "newest") params.set("sort", sort);
    params.set("month", monthKey);
    if (monthStatus) params.set("month_status", monthStatus);
    void apiFetch<MemberListPage>(`/admin/members?${params}`).then((res) => {
      if (cancelled) return;
      if (res.ok) {
        setList(res.data);
        setFailed(false);
      } else setFailed(true);
    });
    return () => {
      cancelled = true;
    };
  }, [status, employment, district, dues, sort, monthKey, monthStatus, query, page, reloadKey]);

  const taka = (n: number) => formatTaka(n, locale);
  const dueText = (p: NonNullable<MemberListItem["payments"]>) =>
    p.total_due > 0 ? `${taka(p.total_due)} · ${fill(l.monthsDue, { n: digits(p.months_due) })}` : l.paidUp;

  const districtName = (key: string | null) => {
    const d = districts?.find((x) => x.key === key);
    return d ? (locale === "bn" ? d.name_bn : d.name_en) : (key ?? "—");
  };
  const filter = <T,>(setter: (v: T) => void) => (value: T) => {
    setter(value);
    setPage(1);
  };
  const href = (m: MemberListItem) => `/dashboard/programmers/${m.id}`;
  // The name opens the member's page at the payment history (Phase 7f).
  const historyHref = (m: MemberListItem) => (m.payments ? `${href(m)}#payment-history` : href(m));
  const dueBadge = (m: MemberListItem) =>
    m.payments && m.payments.total_due > 0 ? (
      <span className="inline-flex shrink-0 items-center rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-danger">
        {fill(l.dueBadge, { amount: taka(m.payments.total_due) })}
      </span>
    ) : null;

  const monthName = (m: number) =>
    new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", { month: "long", timeZone: "UTC" }).format(new Date(Date.UTC(2000, m - 1, 1)));
  const years = Array.from({ length: 5 }, (_, i) => now.getFullYear() - 4 + i);
  const counts = list?.month_counts?.month === monthKey ? list.month_counts : null;
  const countTile = (label: string, value: number | undefined, tone: string) => (
    <div className={`rounded-control border px-3 py-2.5 ${tone}`}>
      <dt className="text-xs font-semibold">{label}</dt>
      <dd className="mt-0.5 font-heading text-xl font-bold">{value === undefined ? "…" : fill(l.monthMembers, { n: digits(value) })}</dd>
    </div>
  );

  return (
    <div className="space-y-5">
      <p className="max-w-3xl">
        {l.subtitle}
        {me.role === "committee" && <span className="ml-1 font-semibold text-heading">{l.readOnly}</span>}
      </p>

      <Card className="p-4 sm:p-5">
        <div className="flex flex-wrap items-end gap-3">
          <Select label={l.monthPick} value={monthNum} onChange={(e) => filter(setMonthNum)(Number(e.target.value))} className="w-40">
            {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
              <option key={m} value={m}>
                {monthName(m)}
              </option>
            ))}
          </Select>
          <Select label={l.yearPick} value={year} onChange={(e) => filter(setYear)(Number(e.target.value))} className="w-28">
            {years.map((y) => (
              <option key={y} value={y}>
                {digits(y)}
              </option>
            ))}
          </Select>
          <div role="group" aria-label={l.monthShow} className="flex gap-2">
            {MONTH_FILTERS.map((f) => {
              const active = f === monthStatus;
              return (
                <button
                  key={f || "all"}
                  type="button"
                  aria-pressed={active}
                  onClick={() => filter(setMonthStatus)(f)}
                  className={`rounded-full border px-4 py-2.5 text-sm font-semibold transition-colors ${
                    active
                      ? "border-secondary bg-secondary text-secondary-foreground"
                      : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
                  }`}
                >
                  {f === "paid" ? l.monthPaid : f === "due" ? l.monthDue : l.monthAll}
                </button>
              );
            })}
          </div>
        </div>
        <p className="mt-4 text-sm font-semibold text-heading">{fill(l.monthTitle, { month: monthLabel(monthKey, locale) })}</p>
        <dl className="mt-2 grid grid-cols-3 gap-2 sm:max-w-xl" aria-live="polite">
          {countTile(l.monthPaid, counts?.paid, "border-success/30 bg-success-soft/50 text-success")}
          {countTile(l.monthDue, counts?.due, "border-danger/30 bg-danger-soft/50 text-danger")}
          {countTile(l.monthPending, counts?.pending, "border-warning/40 bg-warning-soft/60 text-foreground")}
        </dl>
      </Card>

      <Card className="p-4 sm:p-5">
        <form role="search" onSubmit={(e) => e.preventDefault()} className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <Input
            label={l.search}
            type="search"
            placeholder={l.searchPlaceholder}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            maxLength={100}
          />
          <Select label={l.status} value={status} onChange={(e) => filter(setStatus)(e.target.value as Status | "")}>
            <option value="">{l.allStatuses}</option>
            {STATUSES.filter((s) => me.role !== "committee" || s === "approved" || s === "suspended").map((s) => (
              <option key={s} value={s}>
                {t.statuses[s]}
              </option>
            ))}
          </Select>
          <Select label={l.employment} value={employment} onChange={(e) => filter(setEmployment)(e.target.value)}>
            <option value="">{l.allEmployment}</option>
            {options?.employment_statuses.map((o) => (
              <option key={o.key} value={o.key}>
                {locale === "bn" ? o.name_bn : o.name_en}
              </option>
            ))}
          </Select>
          <Select label={l.district} value={district} onChange={(e) => filter(setDistrict)(e.target.value)}>
            <option value="">{l.allDistricts}</option>
            {districts?.map((d) => (
              <option key={d.key} value={d.key}>
                {locale === "bn" ? d.name_bn : d.name_en}
              </option>
            ))}
          </Select>
          <Select label={l.dues} value={dues} onChange={(e) => filter(setDues)(e.target.value as typeof dues)}>
            <option value="">{l.allDues}</option>
            <option value="with">{l.withDues}</option>
            <option value="without">{l.withoutDues}</option>
          </Select>
          <Select label={l.sort} value={sort} onChange={(e) => filter(setSort)(e.target.value as typeof sort)}>
            <option value="newest">{l.sortNewest}</option>
            <option value="name">{l.sortName}</option>
            <option value="due">{l.sortDue}</option>
          </Select>
        </form>
      </Card>

      {failed ? (
        <Card className="text-center">
          <p className="text-danger">{l.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {t.profile.retry}
          </Button>
        </Card>
      ) : !list ? (
        <p role="status" className="py-8 text-center text-muted">
          {t.auth.loading}
        </p>
      ) : list.data.length === 0 ? (
        <Card className="text-center text-muted">{l.empty}</Card>
      ) : (
        <>
          <p className="text-sm text-muted">{fill(l.total, { n: digits(list.meta.total) })}</p>

          {/* Desktop: table */}
          <Card className="hidden overflow-x-auto p-0! md:block">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs font-semibold text-muted">
                <tr>
                  <th scope="col" className="px-4 py-3">{l.name}</th>
                  <th scope="col" className="px-4 py-3">{l.mobile}</th>
                  <th scope="col" className="px-4 py-3">{l.district}</th>
                  <th scope="col" className="px-4 py-3">{l.employment}</th>
                  <th scope="col" className="px-4 py-3">{l.status}</th>
                  <th scope="col" className="px-4 py-3 text-right">{l.totalPaid}</th>
                  <th scope="col" className="px-4 py-3">{l.totalDue}</th>
                  <th scope="col" className="px-4 py-3">{l.lastPaid}</th>
                  <th scope="col" className="px-4 py-3"><span className="sr-only">{l.view}</span></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {list.data.map((m) => (
                  <tr key={m.id} onClick={() => router.push(href(m))} className="cursor-pointer hover:bg-background">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        <Avatar name={m.name} url={m.photo_url} className="size-10" rounded="rounded-full" />
                        <div className="min-w-0">
                          <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <Link
                              href={historyHref(m)}
                              onClick={(e) => e.stopPropagation()}
                              title={m.payments ? l.dueBadgeTitle : undefined}
                              className="font-semibold break-words text-heading hover:text-primary-text"
                            >
                              {m.name}
                            </Link>
                            {dueBadge(m)}
                          </div>
                          {m.designation && <p className="text-xs text-muted">{m.designation}</p>}                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 whitespace-nowrap">{m.phone ?? "—"}</td>
                    <td className="px-4 py-3">{districtName(m.district)}</td>
                    <td className="px-4 py-3">
                      {m.employment_status ? <EmploymentBadge status={m.employment_status} options={options} /> : <span className="text-muted">—</span>}
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={m.status} />
                    </td>
                    {m.payments ? (
                      <>
                        <td className="px-4 py-3 text-right whitespace-nowrap">{taka(m.payments.total_paid)}</td>
                        <td className={`px-4 py-3 whitespace-nowrap ${m.payments.total_due > 0 ? "font-semibold text-danger" : "text-success"}`}>
                          {dueText(m.payments)}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap">
                          {m.payments.last_paid_month ? monthLabel(m.payments.last_paid_month, locale) : <span className="text-muted">—</span>}
                        </td>
                      </>
                    ) : (
                      <td colSpan={3} className="px-4 py-3 text-muted">{l.noPayments}</td>
                    )}
                    <td className="px-4 py-3 text-right text-muted">
                      <Icon name="chevronRight" className="inline size-4" />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </Card>

          {/* Phone: cards */}
          <ul className="space-y-3 md:hidden">
            {list.data.map((m) => (
              <li key={m.id}>
                <Link href={historyHref(m)} className="block rounded-card border border-border bg-surface p-4 shadow-card hover:border-primary">
                  <div className="flex items-center gap-3">
                    <Avatar name={m.name} url={m.photo_url} className="size-12" rounded="rounded-full" />
                    <div className="min-w-0 flex-1">
                      <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span className="font-semibold break-words text-heading">{m.name}</span>
                        {dueBadge(m)}
                      </p>
                      <p className="text-sm text-muted">{m.phone ?? "—"} · {districtName(m.district)}</p>
                    </div>
                    <Icon name="chevronRight" className="size-4 shrink-0 text-muted" />
                  </div>
                  <div className="mt-3 flex flex-wrap gap-2">
                    <EmploymentBadge status={m.employment_status} options={options} />
                    <StatusBadge status={m.status} />
                  </div>
                  {m.payments && (
                    <dl className="mt-3 grid grid-cols-3 gap-2 border-t border-border pt-3 text-sm">
                      <div className="min-w-0">
                        <dt className="text-xs text-muted">{l.totalPaid}</dt>
                        <dd className="font-semibold text-heading">{taka(m.payments.total_paid)}</dd>
                      </div>
                      <div className="min-w-0">
                        <dt className="text-xs text-muted">{l.totalDue}</dt>
                        <dd className={`font-semibold ${m.payments.total_due > 0 ? "text-danger" : "text-success"}`}>
                          {m.payments.total_due > 0 ? taka(m.payments.total_due) : l.paidUp}
                        </dd>
                        {m.payments.months_due > 0 && <dd className="text-xs text-muted">{fill(l.monthsDue, { n: digits(m.payments.months_due) })}</dd>}
                      </div>
                      <div className="min-w-0">
                        <dt className="text-xs text-muted">{l.lastPaid}</dt>
                        <dd className="font-semibold text-heading">
                          {m.payments.last_paid_month ? monthLabel(m.payments.last_paid_month, locale) : "—"}
                        </dd>
                      </div>
                    </dl>
                  )}
                </Link>
              </li>
            ))}
          </ul>

          {list.meta.last_page > 1 && (
            <nav aria-label={l.pagination} className="flex items-center justify-between gap-3">
              <Button variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                {t.admin.prev}
              </Button>
              <span className="text-sm text-muted">
                {fill(t.admin.page, { page: digits(list.meta.current_page), last: digits(list.meta.last_page) })}
              </span>
              <Button variant="outline" disabled={page >= list.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                {t.admin.next}
              </Button>
            </nav>
          )}
        </>
      )}
    </div>
  );
}
