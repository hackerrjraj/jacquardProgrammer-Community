"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { monthLabel, PaymentBadge, ProofModal } from "@/components/payments/payments-shared";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { apiFetch, type MemberPaymentHistory } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";

type Load = { ok: true; data: MemberPaymentHistory } | { ok: false };

// Paid = green, waiting = yellow, due (also after a rejected attempt) = red.
const cardTones = {
  paid: "border-success/30 bg-success-soft/40",
  pending: "border-warning/40 bg-warning-soft/50",
  due: "border-danger/30 bg-danger-soft/40",
} as const;

/** A member's month-by-month contributions (MASTER_SPEC §37a.5) — admin, super_admin, committee (read-only). */
export function MemberPaymentHistorySection({ memberId }: { memberId: number }) {
  const t = useDictionary();
  const locale = useLocale();
  const h = t.programmersList.history;
  const [load, setLoad] = useState<Load | null>(null);
  const [retry, setRetry] = useState(0);
  const [year, setYear] = useState("");
  const [proofUrl, setProofUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: MemberPaymentHistory }>(`/admin/members/${memberId}/payments`).then((res) => {
      if (!cancelled) setLoad(res.ok ? { ok: true, data: res.data.data } : { ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [memberId, retry]);

  // Opened from a name in the Programmers List (".../programmers/5#payment-history") → scroll here once loaded.
  useEffect(() => {
    if (load?.ok && window.location.hash === "#payment-history") {
      document.getElementById("payment-history")?.scrollIntoView({ block: "start" });
    }
  }, [load]);

  const taka = (n: number) => formatTaka(n, locale);
  const digits = (n: number) => localizeDigits(String(n), locale);

  if (!load)
    return (
      <Card className="p-5 sm:p-6" aria-busy="true">
        <h2 className="text-lg font-semibold">{h.title}</h2>
        <p role="status" className="sr-only">
          {t.payments.loading}
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-20 animate-pulse rounded-control bg-border/60" />
          ))}
        </div>
        <div className="mt-4 h-40 animate-pulse rounded-control bg-border/60" />
      </Card>
    );

  if (!load.ok)
    return (
      <Card className="p-5 text-center sm:p-6">
        <h2 className="text-lg font-semibold">{h.title}</h2>
        <p className="mt-3 text-danger">{h.loadFailed}</p>
        <Button
          variant="outline"
          className="mt-4"
          onClick={() => {
            setLoad(null);
            setRetry((k) => k + 1);
          }}
        >
          {h.retry}
        </Button>
      </Card>
    );

  const d = load.data;
  const years = [...new Set(d.months.map((m) => m.month.slice(0, 4)))];
  const months = year ? d.months.filter((m) => m.month.startsWith(year)) : d.months;
  const dateOf = (m: MemberPaymentHistory["months"][number]) =>
    m.payment?.approved_at
      ? formatDate(m.payment.approved_at, locale)
      : m.payment?.submitted_at
        ? fill(h.submitted, { date: formatDate(m.payment.submitted_at, locale) })
        : "—";

  const row = (label: string, value: string, mono = false) => (
    <div className="flex justify-between gap-3">
      <dt className="shrink-0 text-muted">{label}</dt>
      <dd className={`min-w-0 text-right text-heading ${mono ? "font-mono text-[0.9em] break-all" : "break-words"}`}>{value}</dd>
    </div>
  );
  const proofButton = (url: string) => (
    <Button variant="outline" size="sm" className="mt-3 self-start" onClick={() => setProofUrl(url)}>
      {t.payments.history.viewProof}
    </Button>
  );

  const total = (label: string, value: string, tone = "text-heading") => (
    <div className="rounded-control border border-border bg-background px-4 py-3">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className={`mt-1 font-heading text-xl font-bold ${tone}`}>{value}</dd>
    </div>
  );

  return (
    <Card id="payment-history" className="scroll-mt-24 p-5 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="min-w-0">
          <h2 className="text-lg font-semibold">{h.title}</h2>
          <p className="mt-1 text-sm text-muted">{fill(h.hint, { month: monthLabel(d.start_month, locale) })}</p>
        </div>
        {years.length > 1 && (
          <Select label={h.year} value={year} onChange={(e) => setYear(e.target.value)} className="w-40">
            <option value="">{h.allYears}</option>
            {years.map((y) => (
              <option key={y} value={y}>
                {digits(Number(y))}
              </option>
            ))}
          </Select>
        )}
      </div>

      {!d.owes_dues ? (
        <p className="mt-5 rounded-control border border-dashed border-border px-4 py-6 text-center text-muted">{h.notOwing}</p>
      ) : (
        <>
          <dl className="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            {total(h.totalPaid, taka(d.total_paid), "text-success")}
            {total(h.totalDue, taka(d.total_due), d.total_due > 0 ? "text-danger" : "text-heading")}
            {total(h.monthsDue, digits(d.months_due))}
            {total(h.pending, taka(d.total_pending))}
          </dl>

          {d.months.every((m) => !m.payment && m.rejected.length === 0) && (
            <p className="mt-4 rounded-control border border-dashed border-border px-4 py-3 text-center text-sm text-muted">{h.noPaymentsYet}</p>
          )}

          {months.length === 0 ? (
            <p className="mt-5 rounded-control border border-dashed border-border px-4 py-6 text-center text-muted">{h.empty}</p>
          ) : (
            <>
              {/* Month cards, newest first (Phase 7f) — one column on phones */}
              <ul className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {months.map((m) => (
                  <li key={m.month} className={`flex flex-col rounded-control border p-4 ${cardTones[m.status]}`}>
                    <div className="flex items-start justify-between gap-2">
                      <h3 className="font-semibold text-heading">{monthLabel(m.month, locale)}</h3>
                      <PaymentBadge status={m.status} t={t} />
                    </div>
                    <p className={`mt-1 font-heading text-xl font-bold ${m.status === "due" ? "text-danger" : "text-heading"}`}>{taka(m.amount)}</p>

                    {m.payment ? (
                      <dl className="mt-3 space-y-1 text-sm">
                        {row(h.date, dateOf(m))}
                        {row(h.method, t.payments.methods[m.payment.method])}
                        {row(h.reference, m.payment.transaction_id ?? "—", true)}
                      </dl>
                    ) : (
                      <p className="mt-3 text-sm text-muted">{h.noPayment}</p>
                    )}
                    {m.payment?.proof_url && proofButton(m.payment.proof_url)}

                    {m.rejected.map((r) => (
                      <div key={r.id} className="mt-3 rounded-control border border-danger/30 bg-surface px-3 py-2 text-xs">
                        <p className="font-semibold text-danger">
                          {fill(h.rejectedAttempt, { date: formatDate(r.rejected_at ?? r.submitted_at ?? "", locale) })}
                        </p>
                        <p className="mt-0.5 break-words text-muted">
                          {t.payments.methods[r.method]}
                          {r.transaction_id && <> · <span className="font-mono">{r.transaction_id}</span></>}
                        </p>
                        {r.reason && (
                          <p className="mt-0.5 break-words text-foreground">
                            {t.payments.history.reason} {r.reason}
                          </p>
                        )}
                        {r.proof_url && proofButton(r.proof_url)}
                      </div>
                    ))}
                  </li>
                ))}
              </ul>
            </>
          )}
        </>
      )}
      {proofUrl && <ProofModal url={proofUrl} onClose={() => setProofUrl(null)} />}
    </Card>
  );
}
