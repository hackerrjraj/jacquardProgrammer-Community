"use client";

import { useRouter } from "next/navigation";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { apiFetch, type Contribution, type Dues, type Paginated } from "@/lib/api";
import { fill, formatDate, isAdminRole } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";
import { PaymentForm } from "./payment-form";
import { monthLabel, PaymentBadge, ProofModal } from "./payments-shared";

type DuesLoad = { key: number; ok: true; dues: Dues } | { key: number; ok: false };

/** /dashboard/payments — the logged-in member's own dues, "Send payment" form and payment history (MASTER_SPEC §26, §28). */
export function MemberPaymentsScreen() {
  return <RequireAuth>{(user) => (isAdminRole(user.role) ? <AdminRedirect /> : <MemberPayments />)}</RequireAuth>;
}

/** Admin / Super Admin are management-only: no dues page, back to the dashboard. */
function AdminRedirect() {
  const router = useRouter();
  useEffect(() => {
    router.replace("/dashboard");
  }, [router]);
  return null;
}

function MemberPayments() {
  const t = useDictionary();
  const p = t.payments;
  const [reload, setReload] = useState(0);
  const [loaded, setLoaded] = useState<DuesLoad | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: Dues }>("/contributions/dues").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: reload, ok: true, dues: res.data.data } : { key: reload, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [reload]);
  // Keep showing the previous numbers while a reload runs (no flicker after a payment is sent).
  const dues = loaded?.ok ? loaded.dues : null;
  const dueMonths = dues?.months.filter((m) => m.status === "due") ?? [];

  return (
    <div className="space-y-6">
      <p className="max-w-3xl">{p.subtitle}</p>

      {flash && (
        <Alert tone="success" className="flex items-start gap-2">
          <Icon name="checkCircle" className="mt-0.5 size-4 shrink-0" />
          <span>{flash}</span>
        </Alert>
      )}

      {!loaded ? (
        <div className="space-y-4" aria-busy="true">
          <p role="status" className="sr-only">
            {p.loading}
          </p>
          <div className="grid gap-4 sm:grid-cols-3">
            {[0, 1, 2].map((i) => (
              <div key={i} className="h-28 animate-pulse rounded-card bg-border/60" />
            ))}
          </div>
          <div className="h-64 animate-pulse rounded-card bg-border/60" />
        </div>
      ) : !dues ? (
        <Card className="text-center">
          <p className="text-danger">{p.loadFailed}</p>
          <Button
            variant="outline"
            className="mt-4"
            onClick={() => {
              setLoaded(null);
              setReload((k) => k + 1);
            }}
          >
            {p.retry}
          </Button>
        </Card>
      ) : (
        <>
          <DuesSummary dues={dues} />
          <DuesMonths dues={dues} />
          {dueMonths.length > 0 ? (
            <PaymentForm
              // A fresh, empty form after every successful payment.
              key={reload}
              dueMonths={dueMonths}
              onSubmitted={(message) => {
                setFlash(message);
                setReload((k) => k + 1);
                window.scrollTo({ top: 0, behavior: "smooth" });
              }}
            />
          ) : (
            <Card className="flex items-start gap-3 p-5 sm:p-6">
              <Icon name="checkCircle" className="mt-0.5 size-6 shrink-0 text-success" />
              <div>
                <h2 className="text-lg font-semibold">{p.form.title}</h2>
                <p className="mt-1">{dues.total_pending > 0 ? p.form.nothingDuePending : p.form.nothingDue}</p>
              </div>
            </Card>
          )}
        </>
      )}

      <PaymentHistory reloadSignal={reload} />
    </div>
  );
}

function DuesSummary({ dues }: { dues: Dues }) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.payments.summary;
  const card = (icon: IconName, label: string, value: string, note: string, tone: string) => (
    <li>
      <Card className="flex h-full flex-col">
        <div className="flex items-center gap-3">
          <span className={`flex size-11 shrink-0 items-center justify-center rounded-xl text-white ${tone}`}>
            <Icon name={icon} className="size-5" />
          </span>
          <p className="text-sm font-medium text-muted">{label}</p>
        </div>
        <p className="mt-4 font-heading text-3xl font-bold text-heading">{value}</p>
        <p className="mt-1 text-sm text-muted">{note}</p>
      </Card>
    </li>
  );
  const pendingMonths = dues.months.filter((m) => m.status === "pending").length;
  const paidMonths = dues.months.filter((m) => m.status === "paid").length;
  const n = (v: number) => localizeDigits(String(v), locale);

  return (
    <section aria-label={s.label}>
      <ul className="grid gap-4 sm:grid-cols-3">
        {card(
          "alert",
          s.due,
          formatTaka(dues.total_due, locale),
          dues.months_due > 0 ? fill(s.months, { n: n(dues.months_due) }) : s.noDue,
          dues.total_due > 0 ? "bg-danger" : "bg-success",
        )}
        {card("clock", s.pending, formatTaka(dues.total_pending, locale), fill(s.months, { n: n(pendingMonths) }), "bg-warning")}
        {card("banknote", s.paid, formatTaka(dues.total_paid, locale), fill(s.months, { n: n(paidMonths) }), "bg-icon-box")}
      </ul>
    </section>
  );
}

/** Every month from the approval month to the current month — newest first, so the current dues are on top. */
function DuesMonths({ dues }: { dues: Dues }) {
  const t = useDictionary();
  const locale = useLocale();
  const p = t.payments;
  const months = [...dues.months].reverse();

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h2 className="text-lg font-semibold">{p.duesTitle}</h2>
          <p className="mt-1 text-sm text-muted">{p.duesHint}</p>
        </div>
        {dues.total_due > 0 && (
          <p className="rounded-full bg-danger-soft px-3 py-1 text-sm font-semibold text-danger">
            {fill(p.totalDueLine, { total: formatTaka(dues.total_due, locale) })}
          </p>
        )}
      </div>
      {months.length === 0 ? (
        <p className="mt-5 text-muted">{p.noMonths}</p>
      ) : (
        <ul className="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
          {months.map((m) => (
            <li
              key={m.month}
              className={`flex items-center justify-between gap-2 rounded-control border px-3 py-2.5 ${
                m.status === "due" ? "border-danger/30 bg-danger-soft/40" : "border-border bg-background"
              }`}
            >
              <span className="min-w-0">
                <span className="block font-medium text-heading">{monthLabel(m.month, locale)}</span>
                <span className="text-sm text-muted">{formatTaka(m.amount, locale)}</span>
              </span>
              <PaymentBadge status={m.status} t={t} />
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

type HistoryLoad = { key: string; ok: true; items: Contribution[]; page: number; last: number; total: number } | { key: string; ok: false };

function PaymentHistory({ reloadSignal }: { reloadSignal: number }) {
  const t = useDictionary();
  const locale = useLocale();
  const h = t.payments.history;
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [proofUrl, setProofUrl] = useState<string | null>(null);
  const requestKey = `${page}|${retry}|${reloadSignal}`;
  const [loaded, setLoaded] = useState<HistoryLoad | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<Contribution>>(`/contributions?page=${page}`).then((res) => {
      if (cancelled) return;
      setLoaded(
        res.ok
          ? { key: requestKey, ok: true, items: res.data.data, page: res.data.meta.current_page, last: res.data.meta.last_page, total: res.data.meta.total }
          : { key: requestKey, ok: false },
      );
    });
    return () => {
      cancelled = true;
    };
  }, [page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const digits = (v: number) => localizeDigits(String(v), locale);

  const detail = (label: string, value: React.ReactNode) => (
    <div className="min-w-0">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="font-medium break-words text-heading">{value}</dd>
    </div>
  );

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{h.title}</h2>
      <p role="status" className="mt-1 text-sm text-muted">
        {current?.ok ? fill(h.total, { n: digits(current.total) }) : !current ? t.payments.loading : ""}
      </p>

      {!current ? (
        <ul className="mt-5 space-y-3" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <li key={i} className="h-28 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : !current.ok ? (
        <div className="mt-5 text-center">
          <p className="text-danger">{h.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setRetry((k) => k + 1)}>
            {t.payments.retry}
          </Button>
        </div>
      ) : current.items.length === 0 ? (
        <div className="mt-5 rounded-control border border-dashed border-border px-4 py-8 text-center">
          <Icon name="wallet" className="mx-auto size-8 text-muted" />
          <p className="mt-2 text-muted">{h.empty}</p>
        </div>
      ) : (
        <>
          <ul className="mt-5 space-y-3">
            {current.items.map((c) => (
              <li key={c.id} className="rounded-card border border-border p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-heading text-xl font-bold text-heading">{formatTaka(c.amount, locale)}</p>
                  <PaymentBadge status={c.status} t={t} />
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                  {detail(h.date, formatDate(c.created_at, locale))}
                  {detail(h.method, t.payments.methods[c.method])}
                  {detail(h.txid, c.transaction_id ? <span className="font-mono text-[0.95em]">{c.transaction_id}</span> : "—")}
                  {detail(h.sender, c.sender_number ? localizeDigits(c.sender_number, locale) : "—")}
                  <div className="col-span-2 sm:col-span-4">
                    {detail(h.months, c.months.map((m) => monthLabel(m.month, locale)).join(", "))}
                  </div>
                </dl>
                {c.status === "rejected" && c.rejection_reason && (
                  <Alert tone="danger" className="mt-3">
                    <span className="font-semibold">{h.reason}</span> {c.rejection_reason}
                  </Alert>
                )}
                {c.status === "rejected" && <p className="mt-2 text-sm text-muted">{h.rejectedNote}</p>}
                {c.proof_url && (
                  <Button variant="outline" size="sm" className="mt-3" onClick={() => setProofUrl(c.proof_url)}>
                    <Icon name="image" className="size-4" />
                    {h.viewProof}
                  </Button>
                )}
              </li>
            ))}
          </ul>
          {current.last > 1 && (
            <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {h.prevPage}
              </Button>
              <span className="text-sm text-muted">{fill(h.page, { page: digits(current.page), last: digits(current.last) })}</span>
              <Button variant="outline" size="sm" disabled={page >= current.last} onClick={() => setPage(page + 1)}>
                {h.nextPage}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </div>
          )}
        </>
      )}

      {proofUrl && <ProofModal url={proofUrl} onClose={() => setProofUrl(null)} />}
    </Card>
  );
}
