"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { StatusBadge } from "@/components/auth/account-view";
import { RequireAuth } from "@/components/auth/require-auth";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { PaymentReminderPopup } from "@/components/dashboard/payment-reminder-popup";
import { Icon, type IconName } from "@/components/ui/icon";
import { monthLabel } from "@/components/payments/payments-shared";
import { apiFetch, type DashboardSummary, type FundMonthly, type User } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatNumber, formatTaka } from "@/lib/format";

type Load = { kind: "loading" } | { kind: "failed" } | { kind: "ok"; summary: DashboardSummary };

/** Dashboard home (MASTER_SPEC §37a): summary cards, by role. */
export function DashboardHome() {
  return <RequireAuth>{(user) => <Home user={user} />}</RequireAuth>;
}

function Home({ user }: { user: User }) {
  const t = useDictionary();
  const h = t.dashboard.home;
  const [load, setLoad] = useState<Load>({ kind: "loading" });
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: DashboardSummary }>("/dashboard/summary").then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { kind: "ok", summary: res.data.data } : { kind: "failed" });
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  const own = user.role === "member";

  return (
    <div className="space-y-6">
      {user.role === "member" || user.role === "committee" ? <PaymentReminderPopup /> : null}
      <Card className="relative overflow-hidden border-transparent bg-hero p-6 text-white sm:p-8">
        <p className="eyebrow">{t.roles[user.role]}</p>
        <h2 className="mt-2 text-2xl font-bold text-white sm:text-3xl">{fill(h.welcome, { name: user.name })}</h2>
        <p className="mt-2 max-w-2xl text-white/75">{own ? h.ownSubtitle : h.allSubtitle}</p>
      </Card>

      {load.kind === "failed" ? (
        <Card className="text-center">
          <p className="text-danger">{h.loadFailed}</p>
          <Button
            variant="outline"
            className="mt-4"
            onClick={() => {
              setLoad({ kind: "loading" });
              setReloadKey((k) => k + 1);
            }}
          >
            {h.retry}
          </Button>
        </Card>
      ) : own ? (
        <OwnSummary user={user} summary={load.kind === "ok" ? load.summary : null} />
      ) : (
        <>
          <AllSummary summary={load.kind === "ok" ? load.summary : null} user={user} />
          <MonthlyCollection />
        </>
      )}
    </div>
  );
}

/** admin / super_admin / committee: the cards of §37a (pending registrations / payments: admins only). */
function AllSummary({ summary, user }: { summary: DashboardSummary | null; user: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.dashboard.home.cards;
  const data = summary?.scope === "all" ? summary : null;
  const count = (n: number | null | undefined) => (data ? formatNumber(n ?? 0, locale) : "…");
  const taka = (n: number | undefined) => (data && n !== undefined ? formatTaka(n, locale) : "…");
  const admin = user.role === "admin" || user.role === "super_admin";
  // "YYYY-MM" of today in Dhaka (the API's month).
  const thisMonth = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Dhaka", year: "numeric", month: "2-digit" }).format(new Date());

  return (
    <section aria-label={t.dashboard.home.summaryLabel}>
      <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard icon="banknote" label={c.totalCollected} value={taka(data?.total_collected)} />
        <StatCard
          icon="calendar"
          label={c.collectedThisMonth}
          value={taka(data?.collected_this_month)}
          note={fill(c.thisMonthNote, { month: monthLabel(thisMonth, locale) })}
        />
        <StatCard
          icon="alert"
          label={c.outstandingDues}
          value={taka(data?.outstanding_dues)}
          note={data ? (data.members_with_dues > 0 ? fill(c.membersWithDues, { n: count(data.members_with_dues) }) : c.noneOwe) : undefined}
          tone={data && data.outstanding_dues > 0 ? "bg-danger" : undefined}
        />
        <StatCard icon="heart" label={c.totalDisbursed} value={taka(data ? (data.total_disbursed ?? 0) : undefined)} />
        <StatCard icon="wallet" label={c.totalExpenses} value={taka(data?.total_expenses)} />
        <StatCard icon="scale" label={c.balance} value={taka(data?.fund_balance)} note={c.balanceNote} />
        <StatCard icon="users" label={c.activeMembers} value={count(data?.active_members)} />
        {admin && (
          <StatCard
            icon="clock"
            label={c.pendingRegistrations}
            value={count(data?.pending_registrations)}
            href="/dashboard/members"
            linkLabel={c.reviewPending}
          />
        )}
        {admin && (
          <StatCard
            icon="wallet"
            label={c.pendingPayments}
            value={count(data?.pending_payments)}
            href="/dashboard/payment-review"
            linkLabel={c.reviewPayments}
          />
        )}
      </ul>
    </section>
  );
}

/** Fund-wide collection per month (admin / super_admin / committee). */
function MonthlyCollection() {
  const t = useDictionary();
  const locale = useLocale();
  const m = t.dashboard.home.monthly;
  const [load, setLoad] = useState<{ ok: true; data: FundMonthly } | { ok: false } | null>(null);
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: FundMonthly }>("/admin/fund/monthly").then((res) => {
      if (!cancelled) setLoad(res.ok ? { ok: true, data: res.data.data } : { ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [retry]);

  const taka = (n: number) => formatTaka(n, locale);
  const people = (n: number) => fill(m.people, { n: formatNumber(n, locale) });

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{m.title}</h2>
      {!load ? (
        <div aria-busy="true" className="mt-4 space-y-2">
          <p role="status" className="sr-only">
            {t.payments.loading}
          </p>
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-12 animate-pulse rounded-control bg-border/60" />
          ))}
        </div>
      ) : !load.ok ? (
        <div className="mt-4 text-center">
          <p className="text-danger">{m.loadFailed}</p>
          <Button
            variant="outline"
            className="mt-3"
            onClick={() => {
              setLoad(null);
              setRetry((k) => k + 1);
            }}
          >
            {t.dashboard.home.retry}
          </Button>
        </div>
      ) : (
        <>
          <p className="mt-1 text-sm text-muted">
            {fill(m.hint, { month: monthLabel(load.data.start_month, locale), n: formatNumber(load.data.members, locale) })}
          </p>
          {load.data.months.length === 0 ? (
            <p className="mt-5 rounded-control border border-dashed border-border px-4 py-6 text-center text-muted">
              {fill(m.empty, { month: monthLabel(load.data.start_month, locale) })}
            </p>
          ) : (
            <>
              {/* Desktop: table */}
              <div className="mt-5 hidden overflow-x-auto md:block">
                <table className="w-full text-left text-sm">
                  <thead className="bg-background text-xs font-semibold text-muted">
                    <tr>
                      <th scope="col" className="px-3 py-2.5">{m.month}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{m.rate}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{m.paid}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{m.waiting}</th>
                      <th scope="col" className="px-3 py-2.5 text-right">{m.due}</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {load.data.months.map((row) => (
                      <tr key={row.month}>
                        <th scope="row" className="px-3 py-2.5 font-medium text-heading">{monthLabel(row.month, locale)}</th>
                        <td className="px-3 py-2.5 text-right">{taka(row.amount)}</td>
                        <td className="px-3 py-2.5 text-right">
                          <span className="font-semibold text-success">{taka(row.paid_amount)}</span>
                          <span className="block text-xs text-muted">{people(row.paid_members)}</span>
                        </td>
                        <td className="px-3 py-2.5 text-right">
                          {taka(row.pending_amount)}
                          <span className="block text-xs text-muted">{people(row.pending_members)}</span>
                        </td>
                        <td className="px-3 py-2.5 text-right">
                          <span className={row.due_amount > 0 ? "font-semibold text-danger" : ""}>{taka(row.due_amount)}</span>
                          <span className="block text-xs text-muted">{people(row.due_members)}</span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="border-t-2 border-border font-semibold">
                    <tr>
                      <th scope="row" className="px-3 py-2.5">{m.totals}</th>
                      <td />
                      <td className="px-3 py-2.5 text-right text-success">{taka(load.data.totals.paid_amount)}</td>
                      <td className="px-3 py-2.5 text-right">{taka(load.data.totals.pending_amount)}</td>
                      <td className="px-3 py-2.5 text-right text-danger">{taka(load.data.totals.due_amount)}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              {/* Phone: one card per month */}
              <ul className="mt-5 space-y-3 md:hidden">
                {load.data.months.map((row) => (
                  <li key={row.month} className="rounded-control border border-border p-3">
                    <div className="flex items-baseline justify-between gap-2">
                      <p className="font-semibold text-heading">{monthLabel(row.month, locale)}</p>
                      <p className="text-sm text-muted">
                        {m.rate}: {taka(row.amount)}
                      </p>
                    </div>
                    <dl className="mt-2 grid grid-cols-3 gap-2 text-sm">
                      {[
                        [m.paid, row.paid_amount, row.paid_members, "text-success"],
                        [m.waiting, row.pending_amount, row.pending_members, ""],
                        [m.due, row.due_amount, row.due_members, row.due_amount > 0 ? "text-danger" : ""],
                      ].map(([label, amount, n, tone]) => (
                        <div key={String(label)} className="min-w-0">
                          <dt className="text-xs text-muted">{label}</dt>
                          <dd className={`font-semibold ${tone}`}>{taka(Number(amount))}</dd>
                          <dd className="text-xs text-muted">{people(Number(n))}</dd>
                        </div>
                      ))}
                    </dl>
                  </li>
                ))}
                <li className="flex flex-wrap justify-between gap-2 rounded-control bg-background px-3 py-2 text-sm font-semibold">
                  <span>{m.totals}</span>
                  <span className="text-success">{taka(load.data.totals.paid_amount)}</span>
                  <span className="text-danger">{taka(load.data.totals.due_amount)}</span>
                </li>
              </ul>
            </>
          )}
        </>
      )}
    </Card>
  );
}

/** member: only their own summary. */
function OwnSummary({ user, summary }: { user: User; summary: DashboardSummary | null }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.dashboard.home.cards;
  const data = summary?.scope === "own" ? summary : null;

  return (
    <section aria-label={t.dashboard.home.summaryLabel}>
      <ul className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <StatCard
          icon="banknote"
          label={c.myTotalPaid}
          value={data ? formatTaka(data.total_paid, locale) : "…"}
          note={data && data.total_pending > 0 ? fill(c.waiting, { amount: formatTaka(data.total_pending, locale) }) : undefined}
        />
        <StatCard
          icon="alert"
          label={c.myDues}
          value={data ? formatTaka(data.total_due, locale) : "…"}
          note={data ? (data.months_due > 0 ? fill(c.monthsDue, { n: formatNumber(data.months_due, locale) }) : c.nothingDue) : undefined}
          tone={data && data.total_due > 0 ? "bg-danger" : undefined}
          href="/dashboard/payments"
          linkLabel={c.openPayments}
        />
        <li>
          <Card className="h-full">
            <div className="flex items-center gap-3">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-icon-box text-white">
                <Icon name="userCheck" className="size-5" />
              </span>
              <p className="text-sm font-medium text-muted">{c.membership}</p>
            </div>
            <div className="mt-4">
              <StatusBadge status={user.status} />
            </div>
            {user.approved_at && (
              <p className="mt-2 text-sm">{fill(c.memberSince, { date: formatDate(user.approved_at, locale) })}</p>
            )}
          </Card>
        </li>
      </ul>
    </section>
  );
}

function StatCard({
  icon,
  label,
  value,
  note,
  tone,
  href,
  linkLabel,
}: {
  icon: IconName;
  label: string;
  value?: string;
  /** Small line under the number */
  note?: string;
  /** Icon box colour (default: brand) */
  tone?: string;
  href?: string;
  linkLabel?: string;
}) {
  return (
    <li>
      <Card className="flex h-full flex-col">
        <div className="flex items-center gap-3">
          <span
            className={`flex size-11 shrink-0 items-center justify-center rounded-xl text-white ${tone ?? "bg-icon-box"}`}
          >
            <Icon name={icon} className="size-5" />
          </span>
          <p className="text-sm font-medium text-muted">{label}</p>
        </div>
        <p className="mt-4 font-heading text-3xl font-bold break-words text-heading">{value}</p>
        {note && <p className="mt-1 text-sm text-muted">{note}</p>}
        {href && linkLabel && (
          <Link href={href} className="mt-auto pt-3 text-sm font-semibold text-primary-text hover:underline">
            {linkLabel} →
          </Link>
        )}
      </Card>
    </li>
  );
}
