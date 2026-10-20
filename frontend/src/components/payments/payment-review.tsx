"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import type { Locale } from "@/i18n/config";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { Avatar } from "@/components/profile/profile-view";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type AdminContribution, type ContributionStatus, type Paginated, type PaymentMethod, type User } from "@/lib/api";
import { fill, toAsciiDigits } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";
import { refreshPendingPayments, usePendingPaymentsCount } from "./pending-payments";
import { METHODS, monthLabel, PaymentBadge, ProofModal } from "./payments-shared";

type Tab = ContributionStatus | "all";
const TABS: Tab[] = ["pending", "approved", "rejected", "all"];
const REASON_MAX = 500;

type Load = { key: string; ok: true; list: Paginated<AdminContribution> } | { key: string; ok: false };
type Dialog = { kind: "approve" | "reject"; payment: AdminContribution };

/** "2026-10-14T09:30:00+06:00" → "১৪ অক্টো, ২০২৬, ৯:৩০ AM" (Dhaka time). */
function formatDateTime(iso: string | null, locale: Locale): string {
  if (!iso) return "";
  return new Intl.DateTimeFormat(locale === "bn" ? "bn-BD" : "en-GB", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Dhaka",
  }).format(new Date(iso));
}

/** /dashboard/payment-review — payment verification queue (MASTER_SPEC §27), admin + super_admin only. */
export function PaymentReviewScreen() {
  return <RequireAuth admin>{(me) => <PaymentReview me={me} />}</RequireAuth>;
}

function PaymentReview({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const { refresh: refreshAuth } = useAuth();
  const r = t.paymentReview;
  const digits = (n: number) => localizeDigits(String(n), locale);
  const waiting = usePendingPaymentsCount();

  const [tab, setTab] = useState<Tab>("pending");
  const [method, setMethod] = useState<PaymentMethod | "">("");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [loaded, setLoaded] = useState<Load | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [dialog, setDialog] = useState<Dialog | null>(null);
  const [busy, setBusy] = useState(false);
  const [reason, setReason] = useState("");
  const [reasonError, setReasonError] = useState<string | null>(null);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [proofUrl, setProofUrl] = useState<string | null>(null);

  const dateError = from && to && to < from ? r.dateError : null;
  const filtered = Boolean(method || query || from || to);

  // Search: wait until typing stops. Bengali digits (০১৭…) match the stored English ones.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(toAsciiDigits(search).trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [search]);

  const params = new URLSearchParams({ sort: "newest", page: String(page) });
  if (tab !== "all") params.set("status", tab);
  if (method) params.set("method", method);
  if (query) params.set("search", query);
  if (from) params.set("from", from);
  if (to) params.set("to", to);
  const qs = params.toString();
  const requestKey = `${qs}|${reloadKey}`;

  useEffect(() => {
    if (dateError) return;
    let cancelled = false;
    void apiFetch<Paginated<AdminContribution>>(`/admin/contributions?${qs}`).then((res) => {
      if (cancelled) return;
      if (res.ok) {
        // The last row of a later page was just decided → step back to the new last page.
        if (res.data.data.length === 0 && page > 1 && res.data.meta.last_page < page) {
          setPage(Math.max(1, res.data.meta.last_page));
          return;
        }
        setLoaded({ key: requestKey, ok: true, list: res.data });
      } else {
        if (res.status === 401 || res.status === 403) void refreshAuth();
        setLoaded({ key: requestKey, ok: false });
      }
    });
    return () => {
      cancelled = true;
    };
  }, [qs, page, requestKey, dateError, refreshAuth]);

  // Keep the old list on screen (dimmed) while a reload runs — no jump after approve / reject.
  const stale = loaded !== null && loaded.key !== requestKey;

  function changeFilter(apply: () => void) {
    apply();
    setPage(1);
    setFlash(null);
  }

  function clearFilters() {
    changeFilter(() => {
      setMethod("");
      setSearch("");
      setQuery("");
      setFrom("");
      setTo("");
    });
  }

  function openDialog(kind: Dialog["kind"], payment: AdminContribution) {
    setDialog({ kind, payment });
    setReason("");
    setReasonError(null);
    setDialogError(null);
  }

  function closeDialog() {
    if (!busy) setDialog(null);
  }

  async function confirm() {
    if (!dialog) return;
    const { kind, payment } = dialog;
    let body: { reason: string } | undefined;
    if (kind === "reject") {
      const text = reason.trim();
      if (!text) return setReasonError(r.errors.required);
      if (text.length > REASON_MAX) return setReasonError(fill(r.errors.tooLong, { max: digits(REASON_MAX) }));
      body = { reason: text };
    }

    setBusy(true);
    setDialogError(null);
    const res = await apiFetch<{ message: string }>(`/admin/contributions/${payment.id}/${kind}`, {
      method: "POST",
      locale,
      body,
    });
    setBusy(false);

    if (res.ok) {
      setDialog(null);
      setFlash(`${payment.member.name}: ${res.data.message}`);
    } else {
      if (res.status === 401) void refreshAuth();
      const text =
        res.status === 0
          ? r.errors.network
          : (res.data?.errors && Object.values(res.data.errors)[0]?.[0]) || res.data?.message || r.errors.generic;
      if (kind === "reject" && res.status === 422 && res.data?.errors?.reason) return setReasonError(text);
      setDialogError(text);
      // 422 "already decided" (another admin was faster): the list below is out of date.
      if (res.status !== 422) return;
    }
    setReloadKey((k) => k + 1);
    void refreshPendingPayments();
  }

  const list = loaded?.ok ? loaded.list : null;
  const monthsText = (p: AdminContribution) => p.months.map((m) => monthLabel(m.month, locale)).join(", ");

  const detail = (label: string, value: React.ReactNode, wide = false) => (
    <div className={`min-w-0 ${wide ? "col-span-2 sm:col-span-3" : ""}`}>
      <dt className="text-xs text-muted">{label}</dt>
      <dd className="font-medium break-words text-heading">{value}</dd>
    </div>
  );

  return (
    <div>
      <p className="mb-6 max-w-3xl">{r.subtitle}</p>

      <div role="group" aria-label={r.tabsLabel} className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        {TABS.map((s) => {
          const active = s === tab;
          return (
            <button
              key={s}
              type="button"
              aria-pressed={active}
              onClick={() => changeFilter(() => setTab(s))}
              className={`rounded-full border px-3 py-2.5 text-sm font-semibold transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {r.tabs[s]}
              {s === "pending" && waiting !== null && ` (${digits(waiting)})`}
            </button>
          );
        })}
      </div>

      <form
        role="search"
        aria-label={r.filtersLabel}
        onSubmit={(e) => e.preventDefault()}
        className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6"
      >
        <Input
          label={r.search}
          type="search"
          placeholder={r.searchPlaceholder}
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            setFlash(null);
          }}
          maxLength={100}
          className="sm:col-span-2"
        />
        <Select
          label={r.method}
          value={method}
          onChange={(e) => changeFilter(() => setMethod(e.target.value as PaymentMethod | ""))}
          className="lg:col-span-2"
        >
          <option value="">{r.allMethods}</option>
          {METHODS.map((m) => (
            <option key={m} value={m}>
              {t.payments.methods[m]}
            </option>
          ))}
        </Select>
        <Input
          label={r.from}
          type="date"
          value={from}
          max={to || undefined}
          onChange={(e) => changeFilter(() => setFrom(e.target.value))}
        />
        <Input
          label={r.to}
          type="date"
          value={to}
          min={from || undefined}
          onChange={(e) => changeFilter(() => setTo(e.target.value))}
          error={dateError ?? undefined}
        />
      </form>
      {filtered && (
        <div className="mt-2 flex justify-end">
          <Button variant="ghost" size="sm" onClick={clearFilters}>
            <Icon name="close" className="size-4" />
            {r.clear}
          </Button>
        </div>
      )}

      {flash && (
        <Alert tone="success" className="mt-4 flex items-start gap-2">
          <Icon name="checkCircle" className="mt-0.5 size-4 shrink-0" />
          <span>{flash}</span>
        </Alert>
      )}

      {dateError ? null : !loaded ? (
        <div className="mt-6 space-y-3" aria-busy="true">
          <p role="status" className="sr-only">
            {r.loading}
          </p>
          {[0, 1, 2].map((i) => (
            <div key={i} className="h-44 animate-pulse rounded-card bg-border/60" />
          ))}
        </div>
      ) : !loaded.ok && !stale ? (
        <Card className="mt-6 text-center">
          <p className="text-danger">{r.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {r.retry}
          </Button>
        </Card>
      ) : !list ? (
        <p role="status" className="mt-6 text-center text-muted">
          {r.loading}
        </p>
      ) : (
        <div aria-busy={stale} className={stale ? "opacity-60 transition-opacity" : "transition-opacity"}>
          <p role="status" className="mt-6 text-sm text-muted">
            {stale ? r.loading : fill(r.total, { n: digits(list.meta.total) })}
          </p>

          {list.data.length === 0 ? (
            <Card className="mt-3 text-center">
              <Icon name="banknote" className="mx-auto size-8 text-muted" />
              <p className="mt-2 text-muted">{filtered ? r.emptyFiltered : tab === "pending" ? r.empty : r.emptyOther}</p>
            </Card>
          ) : (
            <ul className="mt-3 space-y-4">
              {list.data.map((p) => {
                const own = p.member.id === me.id;
                return (
                  <li key={p.id}>
                    <Card className="p-4! sm:p-5!">
                      <div className="flex flex-wrap items-start gap-3">
                        <Avatar name={p.member.name} url={p.member.photo_url} className="size-12" rounded="rounded-full" />
                        <div className="min-w-0 flex-1">
                          <Link
                            href={`/dashboard/programmers/${p.member.id}`}
                            title={r.viewMember}
                            className="font-semibold break-words text-heading hover:text-primary-text hover:underline"
                          >
                            {p.member.name}
                          </Link>
                          {p.member.phone && (
                            <p className="text-sm text-muted">{localizeDigits(p.member.phone, locale)}</p>
                          )}
                        </div>
                        <div className="flex flex-col items-end gap-1">
                          <p className="font-heading text-xl font-bold text-heading">{formatTaka(p.amount, locale)}</p>
                          <PaymentBadge status={p.status} t={t} />
                        </div>
                      </div>

                      <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
                        {detail(r.methodLabel, t.payments.methods[p.method])}
                        {detail(
                          r.txid,
                          p.transaction_id ? <span className="font-mono text-[0.95em]">{p.transaction_id}</span> : "—",
                        )}
                        {detail(r.sender, p.sender_number ? localizeDigits(p.sender_number, locale) : "—")}
                        {detail(r.submitted, formatDateTime(p.created_at, locale))}
                        {detail(r.months, monthsText(p), true)}
                      </dl>

                      {p.status === "rejected" && p.rejection_reason && (
                        <Alert tone="danger" className="mt-3">
                          <span className="font-semibold">{r.reason}</span> {p.rejection_reason}
                        </Alert>
                      )}
                      {p.status !== "pending" && p.reviewed_by && (
                        <p className="mt-2 text-sm text-muted">
                          {fill(r.reviewed, { name: p.reviewed_by.name, date: formatDateTime(p.reviewed_at, locale) })}
                        </p>
                      )}

                      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                        {p.proof_url ? (
                          <Button variant="outline" size="sm" onClick={() => setProofUrl(p.proof_url)}>
                            <Icon name="image" className="size-4" />
                            {r.viewProof}
                          </Button>
                        ) : (
                          <span className="text-sm text-muted">{r.noProof}</span>
                        )}
                        {p.status === "pending" &&
                          (own ? (
                            <p className="w-full rounded-control bg-warning-soft px-3 py-2 text-sm text-foreground sm:ml-auto sm:w-auto">
                              {r.ownNote}
                            </p>
                          ) : (
                            <div className="flex w-full gap-2 sm:ml-auto sm:w-auto">
                              <Button
                                size="sm"
                                variant="outline"
                                className="flex-1 sm:flex-none"
                                disabled={busy || stale}
                                onClick={() => openDialog("reject", p)}
                              >
                                {r.reject}
                              </Button>
                              <Button
                                size="sm"
                                className="flex-1 sm:flex-none"
                                disabled={busy || stale}
                                onClick={() => openDialog("approve", p)}
                              >
                                <Icon name="checkCircle" className="size-4" />
                                {r.approve}
                              </Button>
                            </div>
                          ))}
                      </div>
                    </Card>
                  </li>
                );
              })}
            </ul>
          )}

          {list.meta.last_page > 1 && (
            <nav className="mt-6 flex items-center justify-between gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1 || stale} onClick={() => setPage((n) => n - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {r.prev}
              </Button>
              <span className="text-sm text-muted">
                {fill(r.page, { page: digits(list.meta.current_page), last: digits(list.meta.last_page) })}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= list.meta.last_page || stale}
                onClick={() => setPage((n) => n + 1)}
              >
                {r.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}

      {dialog && (
        <Modal title={dialog.kind === "approve" ? r.approveTitle : r.rejectTitle} onClose={closeDialog}>
          <p>
            {fill(dialog.kind === "approve" ? r.approveText : r.rejectText, {
              name: dialog.payment.member.name,
              amount: formatTaka(dialog.payment.amount, locale),
              method: t.payments.methods[dialog.payment.method],
              months: monthsText(dialog.payment),
            })}
          </p>
          {dialog.kind === "approve" ? (
            <Alert tone="warning" className="mt-3">
              {r.approveWarn}
            </Alert>
          ) : (
            <Textarea
              label={r.rejectReason}
              className="mt-4"
              rows={3}
              placeholder={r.rejectPlaceholder}
              value={reason}
              maxLength={REASON_MAX}
              onChange={(e) => {
                setReason(e.target.value);
                setReasonError(null);
              }}
              error={reasonError ?? undefined}
              required
            />
          )}
          {dialogError && <Alert className="mt-4">{dialogError}</Alert>}
          <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="ghost" disabled={busy} onClick={closeDialog}>
              {r.cancel}
            </Button>
            <Button variant={dialog.kind === "approve" ? "primary" : "secondary"} disabled={busy} onClick={() => void confirm()}>
              {busy ? r.working : dialog.kind === "approve" ? r.approveConfirm : r.rejectConfirm}
            </Button>
          </div>
        </Modal>
      )}

      {proofUrl && <ProofModal url={proofUrl} onClose={() => setProofUrl(null)} />}
    </div>
  );
}
