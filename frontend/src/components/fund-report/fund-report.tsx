"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { monthLabel } from "@/components/payments/payments-shared";
import { apiFetch, type FundReport, type FundReportCase, type FundReportExpense, type Paginated } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatNumber, formatTaka, localizeDigits, optionName } from "@/lib/format";

type Load<T> = { key: string; ok: true; data: T } | { key: string; ok: false; status: number };

/** Grey boxes while the report loads. */
export function FundReportSkeleton() {
  return (
    <div className="space-y-8" aria-busy="true">
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }, (_, i) => (
          <li key={i} className="h-32 animate-pulse rounded-card border border-border bg-surface shadow-card" />
        ))}
      </ul>
      <div className="h-64 animate-pulse rounded-card border border-border bg-surface shadow-card" />
    </div>
  );
}

/**
 * Fund Report — members only (MASTER_SPEC §33): totals, month-by-month money in / out and the paid aid cases
 * (20 per page, page number in the URL as ?page=2). Names only with the member's consent — the API leaves them out otherwise.
 */
export function FundReportView() {
  const t = useDictionary();
  const locale = useLocale();
  const r = t.fundReport;
  const taka = (v: number) => formatTaka(v, locale);
  const digits = (v: number) => localizeDigits(String(v), locale);

  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const rawPage = Number(searchParams.get("page"));
  const page = Number.isInteger(rawPage) && rawPage > 1 && rawPage < 100000 ? rawPage : 1;

  const [reloadKey, setReloadKey] = useState(0);
  const summaryKey = String(reloadKey);
  const casesKey = `${page}|${reloadKey}`;
  const [summary, setSummary] = useState<Load<FundReport> | null>(null);
  const [cases, setCases] = useState<Load<Paginated<FundReportCase>> | null>(null);
  const [expenses, setExpenses] = useState<Load<Paginated<FundReportExpense>> | null>(null);
  const [expPage, setExpPage] = useState(1);
  const expKey = `${expPage}|${reloadKey}`;

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: FundReport }>("/fund-report").then((res) => {
      if (!cancelled)
        setSummary(res.ok ? { key: summaryKey, ok: true, data: res.data.data } : { key: summaryKey, ok: false, status: res.status });
    });
    return () => {
      cancelled = true;
    };
  }, [summaryKey]);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<FundReportCase>>(`/fund-report/cases?page=${page}`).then((res) => {
      if (!cancelled) setCases(res.ok ? { key: casesKey, ok: true, data: res.data } : { key: casesKey, ok: false, status: res.status });
    });
    return () => {
      cancelled = true;
    };
  }, [page, casesKey]);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<FundReportExpense>>(`/fund-report/expenses?page=${expPage}`).then((res) => {
      if (!cancelled) setExpenses(res.ok ? { key: expKey, ok: true, data: res.data } : { key: expKey, ok: false, status: res.status });
    });
    return () => {
      cancelled = true;
    };
  }, [expPage, expKey]);

  const ex = expenses?.key === expKey ? expenses : null;
  const s = summary?.key === summaryKey ? summary : null;
  const c = cases?.key === casesKey ? cases : null;
  const goTo = (p: number) => router.push(p > 1 ? `${pathname}?page=${p}` : pathname, { scroll: false });
  const retry = () => setReloadKey((k) => k + 1);

  if (!s)
    return (
      <>
        <p role="status" className="sr-only">
          {r.loading}
        </p>
        <FundReportSkeleton />
      </>
    );

  if (!s.ok)
    return (
      <Card className="mx-auto max-w-lg p-8 text-center">
        <Icon name="alert" className="mx-auto size-8 text-danger" />
        <p className="mt-3 text-danger">{s.status === 429 ? r.tooMany : r.loadFailed}</p>
        <Button variant="outline" className="mt-5" onClick={retry}>
          {r.retry}
        </Button>
      </Card>
    );

  const d = s.data;
  const cards: [IconName, string, string, string?][] = [
    ["banknote", r.totalCollected, taka(d.total_collected)],
    ["heart", r.totalDisbursed, taka(d.total_disbursed)],
    ["wallet", r.totalExpenses, taka(d.total_expenses)],
    ["scale", r.balance, taka(d.fund_balance), "bg-secondary"],
    ["users", r.casesPaid, formatNumber(d.cases_paid, locale)],
  ];

  return (
    <div className="space-y-10">
      {/* Totals */}
      <section aria-label={r.heroTitle}>
        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          {cards.map(([icon, label, value, tone]) => (
            <li key={label}>
              <Card className="flex h-full flex-col">
                <div className="flex items-center gap-3">
                  <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl text-white ${tone ?? "bg-icon-box"}`}>
                    <Icon name={icon} className="size-5" />
                  </span>
                  <p className="text-sm font-medium text-muted">{label}</p>
                </div>
                <p className="mt-4 font-heading text-3xl font-bold break-words text-heading">{value}</p>
              </Card>
            </li>
          ))}
        </ul>
        {/* Phase 12b: corrections only as one net total (no reasons, no names). */}
        {d.corrections !== 0 && (
          <p className="mt-3 text-sm">
            <span className="font-semibold text-heading">
              {r.corrections}: {d.corrections > 0 ? "+" : "−"}
              {taka(Math.abs(d.corrections))}
            </span>{" "}
            <span className="text-muted">— {r.correctionsNote}</span>
          </p>
        )}
        <p className="mt-3 text-sm text-muted">{fill(r.updated, { date: formatDate(d.updated_at, locale) })}</p>
      </section>

      {/* Month by month */}
      <section aria-labelledby="fund-monthly">
        <Card className="sm:p-6">
          <h2 id="fund-monthly" className="text-xl font-bold text-heading">
            {r.monthlyTitle}
          </h2>
          <p className="mt-1 text-sm text-muted">{r.monthlyText}</p>

          <div className="mt-5 hidden overflow-x-auto md:block">
            <table className="w-full text-left text-sm">
              <thead className="bg-background text-xs font-semibold text-muted">
                <tr>
                  <th scope="col" className="px-3 py-2.5">{r.month}</th>
                  <th scope="col" className="px-3 py-2.5 text-right">{r.collected}</th>
                  <th scope="col" className="px-3 py-2.5 text-right">{r.disbursed}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {d.monthly.map((row) => (
                  <tr key={row.month}>
                    <th scope="row" className="px-3 py-2.5 font-medium text-heading">{monthLabel(row.month, locale)}</th>
                    <td className="px-3 py-2.5 text-right font-semibold text-success">{taka(row.collected)}</td>
                    <td className="px-3 py-2.5 text-right">{taka(row.disbursed)}</td>
                  </tr>
                ))}
              </tbody>
              <tfoot className="border-t-2 border-border font-semibold">
                <tr>
                  <th scope="row" className="px-3 py-2.5">{r.total}</th>
                  <td className="px-3 py-2.5 text-right text-success">{taka(d.total_collected)}</td>
                  <td className="px-3 py-2.5 text-right">{taka(d.total_disbursed)}</td>
                </tr>
              </tfoot>
            </table>
          </div>

          <ul className="mt-5 space-y-3 md:hidden">
            {d.monthly.map((row) => (
              <li key={row.month} className="rounded-control border border-border p-3">
                <p className="font-semibold text-heading">{monthLabel(row.month, locale)}</p>
                <dl className="mt-2 grid grid-cols-2 gap-2 text-sm">
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">{r.collected}</dt>
                    <dd className="font-semibold text-success">{taka(row.collected)}</dd>
                  </div>
                  <div className="min-w-0">
                    <dt className="text-xs text-muted">{r.disbursed}</dt>
                    <dd className="font-semibold">{taka(row.disbursed)}</dd>
                  </div>
                </dl>
              </li>
            ))}
          </ul>
        </Card>
      </section>

      {/* Paid aid cases */}
      <section aria-labelledby="fund-cases">
        <Card className="sm:p-6">
          <h2 id="fund-cases" className="text-xl font-bold text-heading">
            {r.casesTitle}
          </h2>
          <p className="mt-1 text-sm text-muted">{r.casesText}</p>

          {!c ? (
            <div className="mt-5 h-24 animate-pulse rounded-control bg-border/60" aria-busy="true" />
          ) : !c.ok ? (
            <div className="mt-5 text-center">
              <p className="text-danger">{c.status === 429 ? r.tooMany : r.casesFailed}</p>
              <Button variant="outline" size="sm" className="mt-3" onClick={retry}>
                {r.retry}
              </Button>
            </div>
          ) : c.data.data.length === 0 ? (
            <p className="mt-5 rounded-control bg-background p-4 text-center text-muted">{r.casesEmpty}</p>
          ) : (
            <>
              <p className="mt-4 text-sm text-muted">{fill(r.casesTotal, { n: digits(c.data.meta.total) })}</p>

              <div className="mt-3 hidden overflow-x-auto md:block">
                <table className="w-full text-left text-sm">
                  <thead className="bg-background text-xs font-semibold text-muted">
                    <tr>
                      <th scope="col" className="px-3 py-2.5">{r.caseId}</th>
                      <th scope="col" className="px-3 py-2.5">{r.reason}</th>
                      <th scope="col" className="px-3 py-2.5">{r.recipient}</th>
                      <th scope="col" className="px-3 py-2.5">{r.date}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{r.amount}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {c.data.data.map((row) => (
                      <tr key={row.case_id}>
                        <th scope="row" className="px-3 py-2.5 font-medium whitespace-nowrap text-heading">{row.case_id}</th>
                        <td className="px-3 py-2.5">{t.assistance.reasons[row.reason]}</td>
                        <td className="px-3 py-2.5">{row.recipient_name ?? <span className="text-muted">{r.nameHidden}</span>}</td>
                        <td className="px-3 py-2.5 whitespace-nowrap">{formatDate(row.date, locale)}</td>
                        <td className="px-3 py-2.5 text-right font-semibold whitespace-nowrap">{taka(row.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <ul className="mt-3 space-y-3 md:hidden">
                {c.data.data.map((row) => (
                  <li key={row.case_id} className="rounded-control border border-border p-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                      <p className="font-semibold break-all text-heading">{row.case_id}</p>
                      <p className="font-semibold">{taka(row.amount)}</p>
                    </div>
                    <p className="mt-1 text-sm">{t.assistance.reasons[row.reason]}</p>
                    <p className="mt-1 text-sm text-muted">
                      {formatDate(row.date, locale)} · {row.recipient_name ?? r.nameHidden}
                    </p>
                  </li>
                ))}
              </ul>

              {c.data.meta.last_page > 1 && (
                <nav
                  aria-label={fill(r.page, { page: digits(c.data.meta.current_page), last: digits(c.data.meta.last_page) })}
                  className="mt-5 flex flex-wrap items-center justify-center gap-3"
                >
                  <Button variant="outline" size="sm" disabled={c.data.meta.current_page <= 1} onClick={() => goTo(c.data.meta.current_page - 1)}>
                    <Icon name="chevronLeft" className="size-4" />
                    {r.prevPage}
                  </Button>
                  <span className="text-sm text-muted">
                    {fill(r.page, { page: digits(c.data.meta.current_page), last: digits(c.data.meta.last_page) })}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={c.data.meta.current_page >= c.data.meta.last_page}
                    onClick={() => goTo(c.data.meta.current_page + 1)}
                  >
                    {r.nextPage}
                    <Icon name="chevronRight" className="size-4" />
                  </Button>
                </nav>
              )}
            </>
          )}
        </Card>
      </section>

      {/* Expenses */}
      <section aria-labelledby="fund-expenses">
        <Card className="sm:p-6">
          <h2 id="fund-expenses" className="text-xl font-bold text-heading">
            {r.expTitle}
          </h2>
          <p className="mt-1 text-sm text-muted">{r.expText}</p>
          <p className="mt-3 text-sm">
            <span className="font-semibold text-heading">
              {r.balanceAfter}: {taka(d.fund_balance)}
            </span>{" "}
            <span className="text-muted">
              ({taka(d.total_collected)} − {taka(d.total_disbursed)} − {taka(d.total_expenses)}
              {d.corrections !== 0 ? ` ${d.corrections > 0 ? "+" : "−"} ${taka(Math.abs(d.corrections))}` : ""})
            </span>
          </p>

          {!ex ? (
            <div className="mt-5 h-24 animate-pulse rounded-control bg-border/60" aria-busy="true" />
          ) : !ex.ok ? (
            <div className="mt-5 text-center">
              <p className="text-danger">{ex.status === 429 ? r.tooMany : r.expFailed}</p>
              <Button variant="outline" size="sm" className="mt-3" onClick={retry}>
                {r.retry}
              </Button>
            </div>
          ) : ex.data.data.length === 0 ? (
            <p className="mt-5 rounded-control bg-background p-4 text-center text-muted">{r.expEmpty}</p>
          ) : (
            <>
              <p className="mt-4 text-sm text-muted">{fill(r.expTotal, { n: digits(ex.data.meta.total) })}</p>
              <div className="mt-3 hidden overflow-x-auto md:block">
                <table className="w-full text-left text-sm">
                  <thead className="bg-background text-xs font-semibold text-muted">
                    <tr>
                      <th scope="col" className="px-3 py-2.5">{r.expDate}</th>
                      <th scope="col" className="px-3 py-2.5">{r.expCategory}</th>
                      <th scope="col" className="px-3 py-2.5">{r.expPurpose}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{r.expAmount}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {ex.data.data.map((row) => (
                      <tr key={row.id}>
                        <td className="px-3 py-2.5 whitespace-nowrap">{formatDate(row.date, locale)}</td>
                        <td className="px-3 py-2.5">{row.category ? optionName(row.category, locale) : "—"}</td>
                        <td className="max-w-md px-3 py-2.5 break-words">{row.purpose}</td>
                        <td className="px-3 py-2.5 text-right font-semibold whitespace-nowrap">{taka(row.amount)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <ul className="mt-3 space-y-3 md:hidden">
                {ex.data.data.map((row) => (
                  <li key={row.id} className="rounded-control border border-border p-3">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                      <p className="font-semibold text-heading">{row.category ? optionName(row.category, locale) : "—"}</p>
                      <p className="font-semibold">{taka(row.amount)}</p>
                    </div>
                    <p className="mt-1 text-sm break-words">{row.purpose}</p>
                    <p className="mt-1 text-sm text-muted">{formatDate(row.date, locale)}</p>
                  </li>
                ))}
              </ul>
              {ex.data.meta.last_page > 1 && (
                <nav className="mt-5 flex flex-wrap items-center justify-center gap-3">
                  <Button variant="outline" size="sm" disabled={ex.data.meta.current_page <= 1} onClick={() => setExpPage(expPage - 1)}>
                    <Icon name="chevronLeft" className="size-4" />
                    {r.prevPage}
                  </Button>
                  <span className="text-sm text-muted">
                    {fill(r.page, { page: digits(ex.data.meta.current_page), last: digits(ex.data.meta.last_page) })}
                  </span>
                  <Button variant="outline" size="sm" disabled={ex.data.meta.current_page >= ex.data.meta.last_page} onClick={() => setExpPage(expPage + 1)}>
                    {r.nextPage}
                    <Icon name="chevronRight" className="size-4" />
                  </Button>
                </nav>
              )}
            </>
          )}
        </Card>
      </section>

      {/* Privacy note */}
      <Card className="flex gap-3 border-secondary/30 bg-background">
        <Icon name="lock" className="mt-0.5 size-5 shrink-0 text-primary-text" />
        <div>
          <p className="font-semibold text-heading">{r.privacyTitle}</p>
          <p className="mt-1 text-sm">{r.privacyText}</p>
        </div>
      </Card>
    </div>
  );
}
