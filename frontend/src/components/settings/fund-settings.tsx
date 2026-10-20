"use client";

import { useCallback, useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { failureText } from "@/components/profile/profile-shared";
import { monthLabel } from "@/components/payments/payments-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { apiFetch, type ApiResult, type FundSettings } from "@/lib/api";
import { fill, formatDate, toAsciiDigits } from "@/lib/auth";
import { formatTaka } from "@/lib/format";
import { AccountsSection } from "./settings-accounts";
import { IntegritySection, LedgerSection } from "./settings-ledger";
import { SectionTitle, type SectionProps } from "./settings-shared";

/** How far ahead a new monthly amount can be planned in the month list. */
const RATE_MONTHS_AHEAD = 24;
const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;

/** "2026-10" + 3 → "2027-01". */
export function addMonths(month: string, n: number): string {
  const [y, m] = month.split("-").map(Number);
  const d = new Date(Date.UTC(y, m - 1 + n, 1));
  return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}`;
}

/** The amount that applies to `month` (rates newest first). */
function amountFor(rates: FundSettings["rates"], month: string): number | null {
  return rates.find((r) => r.effective_from <= month)?.amount ?? null;
}

/** /dashboard/settings — Fund Settings (MASTER_SPEC §25, §37), Super Admin only. */
export function FundSettingsScreen() {
  return <RequireAuth roles={["super_admin"]}>{() => <FundSettingsPage />}</RequireAuth>;
}

function FundSettingsPage() {
  const t = useDictionary();
  const s = t.fundSettings;
  const { refresh: refreshAuth } = useAuth();
  const [reload, setReload] = useState(0);
  const [data, setData] = useState<FundSettings | null | "failed">(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: FundSettings }>("/admin/settings").then((res) => {
      if (cancelled) return;
      if (res.ok) setData(res.data.data);
      else {
        if (res.status === 401 || res.status === 403) void refreshAuth();
        setData("failed");
      }
    });
    return () => {
      cancelled = true;
    };
  }, [reload, refreshAuth]);

  // Stable, so the ledger sections do not reload on every render.
  const onAuthError = useCallback(
    (res: ApiResult<unknown>) => {
      if (!res.ok && (res.status === 401 || res.status === 403)) void refreshAuth();
    },
    [refreshAuth],
  );
  const [ledgerVersion, setLedgerVersion] = useState(0);

  if (data === null) {
    return (
      <div className="space-y-6" aria-busy="true">
        <p role="status" className="sr-only">
          {s.loading}
        </p>
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-56 animate-pulse rounded-card bg-border/60" />
        ))}
      </div>
    );
  }
  if (data === "failed") {
    return (
      <Card className="text-center">
        <p className="text-danger">{s.loadFailed}</p>
        <Button
          variant="outline"
          className="mt-4"
          onClick={() => {
            setData(null);
            setReload((k) => k + 1);
          }}
        >
          {s.retry}
        </Button>
      </Card>
    );
  }

  const props: SectionProps = { data, onSaved: setData, onAuthError };
  return (
    <div className="space-y-6">
      <p className="max-w-3xl">{s.subtitle}</p>
      <RateSection {...props} />
      <AccountsSection {...props} />
      <StartMonthSection {...props} />
      {/* Phase 12b: ledger corrections + read-only integrity check (re-runs after each correction). */}
      <LedgerSection onAuthError={onAuthError} onChanged={() => setLedgerVersion((v) => v + 1)} />
      <IntegritySection onAuthError={onAuthError} version={ledgerVersion} />
    </div>
  );
}

/* ---------- Monthly amount ---------- */

type RateError = "required" | "whole" | "min" | "max" | "same";

function RateSection({ data, onSaved, onAuthError }: SectionProps) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.fundSettings.rate;
  const taka = (n: number) => formatTaka(n, locale);
  const month = (m: string) => monthLabel(m, locale);

  const options = Array.from({ length: RATE_MONTHS_AHEAD }, (_, i) => addMonths(data.next_month, i));
  const [amount, setAmount] = useState("");
  const [from, setFrom] = useState(data.next_month);
  const [error, setError] = useState<{ field: "amount" | "effective_from"; code?: RateError; text?: string } | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [cancelId, setCancelId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const value = Number(toAsciiDigits(amount).trim());
  const planned = data.rates.filter((r) => r.status === "scheduled").reverse();
  const currentId = data.rates.find((r) => r.status === "started")?.id;
  const cancelling = data.rates.find((r) => r.id === cancelId) ?? null;

  function validate(): boolean {
    const raw = toAsciiDigits(amount).trim();
    let code: RateError | undefined;
    if (!raw) code = "required";
    else if (!/^\d+$/.test(raw)) code = "whole";
    else if (value < 1) code = "min";
    else if (value > data.amount_max) code = "max";
    else if (amountFor(data.rates, from) === value) code = "same";
    setError(code ? { field: "amount", code } : null);
    return !code;
  }

  function apply(res: ApiResult<{ message: string; data: FundSettings }>) {
    if (res.ok) {
      onSaved(res.data.data);
      setFlash(res.data.message);
      return true;
    }
    onAuthError(res);
    const errs = res.status === 422 ? res.data?.errors : undefined;
    const key = errs && (["amount", "effective_from"] as const).find((k) => errs[k]?.[0]);
    if (key && errs) setError({ field: key, text: errs[key][0] });
    else setFailure(failureText(res, t));
    return false;
  }

  async function save() {
    setBusy(true);
    const res = await apiFetch<{ message: string; data: FundSettings }>("/admin/settings/rates", {
      method: "POST",
      body: { amount: value, effective_from: from },
    });
    setBusy(false);
    setConfirming(false);
    if (apply(res)) setAmount("");
  }

  async function cancelPlanned(id: number) {
    setBusy(true);
    const res = await apiFetch<{ message: string; data: FundSettings }>(`/admin/settings/rates/${id}`, { method: "DELETE" });
    setBusy(false);
    setCancelId(null);
    apply(res);
  }

  const errorText = (field: "amount" | "effective_from") =>
    error?.field === field
      ? error.text ?? (error.code ? fill(t.fundSettings.rate.errors[error.code], { max: taka(data.amount_max) }) : undefined)
      : undefined;

  return (
    <Card className="p-5 sm:p-6">
      <SectionTitle icon="banknote" title={s.title} />

      <div className="mt-4 flex flex-wrap items-end gap-x-6 gap-y-2 rounded-control border border-border bg-background px-4 py-3">
        <div>
          <p className="text-sm text-muted">{fill(s.currentLabel, { month: month(data.current_month) })}</p>
          <p className="text-2xl font-bold text-heading">
            {taka(data.current_amount)} <span className="text-sm font-normal text-muted">{s.perMonth}</span>
          </p>
        </div>
        {planned.map((r) => (
          <p key={r.id} className="text-sm font-medium text-primary-text">
            {fill(s.plannedNote, { amount: taka(r.amount), month: month(r.effective_from) })}
          </p>
        ))}
      </div>

      {flash && (
        <Alert tone="success" className="mt-4">
          {flash}
        </Alert>
      )}

      <form
        noValidate
        className="mt-5 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          setFailure(null);
          setFlash(null);
          if (validate()) setConfirming(true);
        }}
      >
        <h3 className="font-semibold text-heading">{s.newTitle}</h3>
        <p className="text-sm text-muted">{fill(s.futureNote, { month: month(data.current_month) })}</p>
        <div className="grid gap-4 sm:grid-cols-2">
          <Input
            label={s.amount}
            inputMode="numeric"
            placeholder={String(data.current_amount)}
            value={amount}
            error={errorText("amount")}
            onChange={(e) => {
              setAmount(e.target.value);
              setError(null);
            }}
          />
          <Select
            label={s.from}
            value={from}
            error={errorText("effective_from")}
            onChange={(e) => {
              setFrom(e.target.value);
              setError(null);
            }}
          >
            {options.map((m) => (
              <option key={m} value={m}>
                {month(m)}
              </option>
            ))}
          </Select>
        </div>
        {failure && <Alert tone="danger">{failure}</Alert>}
        <Button type="submit" disabled={busy}>
          {s.submit}
        </Button>
      </form>

      <h3 className="mt-8 font-semibold text-heading">{s.historyTitle}</h3>
      <ul className="mt-2 divide-y divide-border rounded-control border border-border">
        <li className="hidden grid-cols-[1.3fr_1fr_1fr_1.5fr_auto] gap-3 bg-background px-4 py-2 text-xs font-semibold text-muted uppercase sm:grid">
          <span>{s.colFrom}</span>
          <span>{s.colAmount}</span>
          <span>{s.colStatus}</span>
          <span>{s.colBy}</span>
          <span className="w-28" />
        </li>
        {data.rates.map((r) => {
          const status = r.status === "scheduled" ? "planned" : r.id === currentId ? "current" : "old";
          return (
            <li key={r.id} className="grid grid-cols-2 items-center gap-x-3 gap-y-1 px-4 py-3 text-sm sm:grid-cols-[1.3fr_1fr_1fr_1.5fr_auto]">
              <span className="font-medium text-heading">
                {r.effective_from <= data.start_month && !r.created_by ? s.fromStart : month(r.effective_from)}
              </span>
              <span className="text-right font-semibold sm:text-left">{taka(r.amount)}</span>
              <span>
                <span
                  className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                    status === "current"
                      ? "bg-success-soft text-success"
                      : status === "planned"
                        ? "bg-warning-soft text-foreground"
                        : "bg-border/60 text-muted"
                  }`}
                >
                  {status === "current" ? s.statusCurrent : status === "planned" ? s.statusPlanned : s.statusOld}
                </span>
              </span>
              <span className="text-right text-muted sm:text-left">
                {r.created_by ? (
                  <>
                    {r.created_by.name}
                    {r.updated_at && <span className="block text-xs">{formatDate(r.updated_at, locale)}</span>}
                  </>
                ) : (
                  s.byDefault
                )}
              </span>
              <span className="col-span-2 sm:col-span-1 sm:w-28 sm:text-right">
                {status === "planned" && (
                  <Button variant="danger" size="sm" disabled={busy} onClick={() => setCancelId(r.id)}>
                    {s.cancelPlanned}
                  </Button>
                )}
              </span>
            </li>
          );
        })}
      </ul>

      {confirming && (
        <Modal title={s.confirmTitle} onClose={() => !busy && setConfirming(false)}>
          <p>
            {fill(s.confirmText, {
              month: month(from),
              amount: taka(value),
              current: taka(amountFor(data.rates, from) ?? data.current_amount),
            })}
          </p>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={() => setConfirming(false)}>
              {t.fundSettings.cancel}
            </Button>
            <Button disabled={busy} onClick={() => void save()}>
              {busy ? t.fundSettings.saving : s.confirm}
            </Button>
          </div>
        </Modal>
      )}

      {cancelling && (
        <Modal title={s.cancelTitle} onClose={() => !busy && setCancelId(null)}>
          <p>{fill(s.cancelText, { amount: taka(cancelling.amount), month: month(cancelling.effective_from) })}</p>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={() => setCancelId(null)}>
              {t.fundSettings.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void cancelPlanned(cancelling.id)}>
              {s.cancelConfirm}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}

/* ---------- Fund start month ---------- */

type StartError = "required" | "format" | "range" | "same" | "afterPaid" | "afterPending";

function StartMonthSection({ data, onSaved, onAuthError }: SectionProps) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.fundSettings.start;
  const month = (m: string) => monthLabel(m, locale);

  const [value, setValue] = useState("");
  const [error, setError] = useState<{ code: StartError } | { text: string } | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [ticked, setTicked] = useState(false);
  const [busy, setBusy] = useState(false);

  const chosen = toAsciiDigits(value).trim();

  function validate(): boolean {
    let code: StartError | undefined;
    if (!chosen) code = "required";
    else if (!MONTH_RE.test(chosen)) code = "format";
    else if (chosen < data.start_month_min || chosen > data.start_month_max) code = "range";
    else if (chosen === data.start_month) code = "same";
    else if (data.earliest_paid_month && chosen > data.earliest_paid_month) code = "afterPaid";
    else if (data.earliest_pending_month && chosen > data.earliest_pending_month) code = "afterPending";
    setError(code ? { code } : null);
    return !code;
  }

  async function save() {
    setBusy(true);
    const res = await apiFetch<{ message: string; data: FundSettings }>("/admin/settings/start-month", {
      method: "PUT",
      body: { start_month: chosen, confirm: true },
    });
    setBusy(false);
    setConfirming(false);
    if (res.ok) {
      onSaved(res.data.data);
      setFlash(res.data.message);
      setValue("");
      return;
    }
    onAuthError(res);
    const text = res.status === 422 ? res.data?.errors?.start_month?.[0] ?? res.data?.errors?.confirm?.[0] : undefined;
    if (text) setError({ text });
    else setFailure(failureText(res, t));
  }

  const errorText = !error
    ? undefined
    : "text" in error
      ? error.text
      : fill(s.errors[error.code], {
          min: month(data.start_month_min),
          max: month(data.start_month_max),
          month: month(error.code === "afterPending" ? (data.earliest_pending_month ?? "") : (data.earliest_paid_month ?? "")),
        });

  return (
    <Card className="p-5 sm:p-6">
      <SectionTitle icon="calendar" title={s.title} subtitle={s.explain} />

      <div className="mt-4 rounded-control border border-border bg-background px-4 py-3">
        <p className="text-sm text-muted">{s.currentLabel}</p>
        <p className="text-2xl font-bold text-heading">{month(data.start_month)}</p>
      </div>

      <Alert tone="warning" className="mt-4 flex items-start gap-2">
        <Icon name="alert" className="mt-0.5 size-5 shrink-0" />
        <span>
          <strong className="block">{s.warningTitle}</strong>
          {s.warningText}
        </span>
      </Alert>

      {(data.earliest_paid_month || data.earliest_pending_month) && (
        <ul className="mt-3 space-y-1 text-sm text-muted">
          {data.earliest_paid_month && <li>{fill(s.earliestPaid, { month: month(data.earliest_paid_month) })}</li>}
          {data.earliest_pending_month && <li>{fill(s.earliestPending, { month: month(data.earliest_pending_month) })}</li>}
        </ul>
      )}

      {flash && (
        <Alert tone="success" className="mt-4">
          {flash}
        </Alert>
      )}

      <form
        noValidate
        className="mt-5 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          setFailure(null);
          setFlash(null);
          if (validate()) {
            setTicked(false);
            setConfirming(true);
          }
        }}
      >
        <div className="max-w-xs">
          <Input
            label={s.newMonth}
            type="month"
            placeholder="YYYY-MM"
            min={data.start_month_min}
            max={data.start_month_max}
            value={value}
            error={errorText}
            onChange={(e) => {
              setValue(e.target.value);
              setError(null);
            }}
          />
          <p className="mt-1 text-xs text-muted">
            {fill(s.monthHint, { min: month(data.start_month_min), max: month(data.start_month_max) })}
          </p>
        </div>
        {failure && <Alert tone="danger">{failure}</Alert>}
        <Button type="submit" variant="secondary" disabled={busy}>
          {s.submit}
        </Button>
      </form>

      {confirming && (
        <Modal title={s.confirmTitle} onClose={() => !busy && setConfirming(false)}>
          <p>{fill(s.confirmText, { old: month(data.start_month), new: month(chosen) })}</p>
          <label className="mt-4 flex cursor-pointer items-start gap-2 rounded-control border border-warning/40 bg-warning-soft px-3 py-2.5 text-sm">
            <input type="checkbox" className="mt-0.5 size-4 accent-primary" checked={ticked} onChange={(e) => setTicked(e.target.checked)} />
            <span>{s.confirmTick}</span>
          </label>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={() => setConfirming(false)}>
              {t.fundSettings.cancel}
            </Button>
            <Button variant="danger" disabled={busy || !ticked} onClick={() => void save()}>
              {busy ? t.fundSettings.saving : s.confirm}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}

