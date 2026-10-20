"use client";

import { useCallback, useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  apiFetch,
  type ApiResult,
  type IntegrityResult,
  type LedgerEntry,
  type LedgerEntryType,
  type LedgerFigures,
} from "@/lib/api";
import { fill, formatDate, toAsciiDigits } from "@/lib/auth";
import { formatTaka } from "@/lib/format";
import { SectionTitle } from "./settings-shared";

const TYPES: LedgerEntryType[] = ["contribution", "disbursement", "adjustment", "reversal"];
const MAX_AMOUNT = 10_000_000;

type LedgerPage = {
  data: LedgerEntry[];
  meta: LedgerFigures & { current_page: number; last_page: number; total: number };
};
type Saved = { message: string; data: LedgerEntry; meta: LedgerFigures };

/** What the confirm box shows before anything is saved: amount, balance now, balance after. */
type Pending =
  | { kind: "adjust"; direction: "in" | "out"; amount: number; reason: string }
  | { kind: "reverse"; entry: LedgerEntry; reason: string };

/** Signed taka: "+৳ 700" / "−৳ 200" / "৳ 0". */
function useSignedTaka() {
  const locale = useLocale();
  return (n: number) => `${n > 0 ? "+" : n < 0 ? "−" : ""}${formatTaka(Math.abs(n), locale)}`;
}

/**
 * Fund ledger + corrections (Phase 12b, MASTER_SPEC §34) — Super Admin only (the page and the API both check).
 * Existing entries are never changed: an adjustment or a reversal is a new entry, confirmed with the new balance.
 */
export function LedgerSection({ onAuthError, onChanged }: { onAuthError: (res: ApiResult<unknown>) => void; onChanged: () => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.fundSettings.ledger;
  const taka = (n: number) => formatTaka(n, locale);
  const signed = useSignedTaka();

  const [type, setType] = useState<LedgerEntryType | "">("");
  const [page, setPage] = useState(1);
  const [reload, setReload] = useState(0);
  const [list, setList] = useState<LedgerPage | null | "failed">(null);

  const [direction, setDirection] = useState<"in" | "out">("in");
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<{ amount?: string; reason?: string }>({});
  const [pending, setPending] = useState<Pending | null>(null);
  const [reverseOf, setReverseOf] = useState<LedgerEntry | null>(null);
  const [reverseReason, setReverseReason] = useState("");
  const [reverseError, setReverseError] = useState<string | undefined>();
  const [failure, setFailure] = useState<string | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    const q = new URLSearchParams({ page: String(page), ...(type ? { type } : {}) });
    void apiFetch<LedgerPage>(`/admin/ledger?${q}`).then((res) => {
      if (cancelled) return;
      if (res.ok) setList(res.data);
      else {
        onAuthError(res);
        setList("failed");
      }
    });
    return () => {
      cancelled = true;
    };
  }, [page, type, reload, onAuthError]);

  const figures = list && list !== "failed" ? list.meta : null;
  const effectOf = (p: Pending) => (p.kind === "adjust" ? (p.direction === "out" ? -p.amount : p.amount) : -p.entry.effect);

  function checkAdjust() {
    const raw = toAsciiDigits(amount).trim();
    const value = Number(raw);
    const next: typeof errors = {};
    if (!/^\d+$/.test(raw) || value < 1 || value > MAX_AMOUNT) next.amount = s.errors.amountRequired;
    if (reason.trim().length < 3) next.reason = s.errors.reasonRequired;
    if (!next.amount && figures && direction === "out" && figures.balance - value < 0) {
      next.amount = fill(s.errors.negative, { amount: signed(figures.balance - value) });
    }
    setErrors(next);
    if (!next.amount && !next.reason) setPending({ kind: "adjust", direction, amount: value, reason: reason.trim() });
  }

  function checkReverse() {
    if (!reverseOf) return;
    if (reverseReason.trim().length < 3) {
      setReverseError(s.errors.reasonRequired);
      return;
    }
    setPending({ kind: "reverse", entry: reverseOf, reason: reverseReason.trim() });
    setReverseOf(null);
  }

  async function save(p: Pending) {
    setBusy(true);
    setFailure(null);
    const res =
      p.kind === "adjust"
        ? await apiFetch<Saved>("/admin/ledger/adjustments", { method: "POST", body: { direction: p.direction, amount: p.amount, reason: p.reason } })
        : await apiFetch<Saved>(`/admin/ledger/${p.entry.id}/reverse`, { method: "POST", body: { reason: p.reason } });
    setBusy(false);
    setPending(null);
    if (res.ok) {
      setFlash(res.data.message);
      if (p.kind === "adjust") {
        setAmount("");
        setReason("");
      } else setReverseReason("");
      setPage(1);
      setReload((k) => k + 1);
      onChanged();
      return;
    }
    onAuthError(res);
    const errs = res.status === 422 ? res.data?.errors : undefined;
    setFailure(errs ? Object.values(errs)[0]?.[0] ?? failureText(res, t) : failureText(res, t));
  }

  const details = (e: LedgerEntry) =>
    [
      e.member && fill(s.member, { name: e.member.name }),
      e.case_id && fill(s.caseId, { id: e.case_id }),
      e.payment_id && fill(s.payment, { id: e.payment_id }),
      e.reverses && fill(s.reverses, { id: e.reverses.id }),
      e.reversed_by && fill(s.reversedBy, { id: e.reversed_by }),
    ].filter(Boolean) as string[];

  return (
    <Card className="p-5 sm:p-6">
      <SectionTitle icon="scale" title={s.title} subtitle={s.subtitle} />

      {figures && (
        <div className="mt-4 flex flex-wrap items-end gap-x-6 gap-y-1 rounded-control border border-border bg-background px-4 py-3">
          <div>
            <p className="text-sm text-muted">{s.balanceLabel}</p>
            <p className="text-2xl font-bold text-heading">{taka(figures.balance)}</p>
          </div>
          <p className="text-sm text-muted">{fill(s.correctionsLabel, { amount: signed(figures.corrections) })}</p>
          {figures.public_balance !== figures.balance && (
            <p className="text-sm text-muted">{fill(s.publicNote, { amount: taka(figures.public_balance) })}</p>
          )}
        </div>
      )}

      {flash && (
        <Alert tone="success" className="mt-4">
          {flash}
        </Alert>
      )}
      {failure && (
        <Alert tone="danger" className="mt-4">
          {failure}
        </Alert>
      )}

      {/* Add an adjustment */}
      <form
        noValidate
        className="mt-5 space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          setFlash(null);
          setFailure(null);
          checkAdjust();
        }}
      >
        <h3 className="font-semibold text-heading">{s.adjustTitle}</h3>
        <div className="grid gap-4 sm:grid-cols-2">
          <Select label={s.direction} value={direction} onChange={(e) => setDirection(e.target.value as "in" | "out")}>
            <option value="in">{s.directionIn}</option>
            <option value="out">{s.directionOut}</option>
          </Select>
          <Input
            label={s.amount}
            inputMode="numeric"
            value={amount}
            error={errors.amount}
            onChange={(e) => {
              setAmount(e.target.value);
              setErrors((x) => ({ ...x, amount: undefined }));
            }}
          />
        </div>
        <Textarea
          label={s.reason}
          rows={2}
          maxLength={500}
          placeholder={s.reasonPlaceholder}
          value={reason}
          error={errors.reason}
          onChange={(e) => {
            setReason(e.target.value);
            setErrors((x) => ({ ...x, reason: undefined }));
          }}
        />
        <Button type="submit" disabled={busy || !figures}>
          {s.adjustSubmit}
        </Button>
      </form>

      {/* Entries */}
      <div className="mt-8 flex flex-wrap items-end justify-between gap-3">
        <h3 className="font-semibold text-heading">{s.listTitle}</h3>
        <div className="w-full sm:w-52">
          <Select
            label={s.filterLabel}
            value={type}
            onChange={(e) => {
              setType(e.target.value as LedgerEntryType | "");
              setPage(1);
            }}
          >
            <option value="">{s.filterAll}</option>
            {TYPES.map((k) => (
              <option key={k} value={k}>
                {s.types[k]}
              </option>
            ))}
          </Select>
        </div>
      </div>

      {list === null && <div className="mt-3 h-40 animate-pulse rounded-control bg-border/60" aria-busy="true" />}
      {list === "failed" && (
        <div className="mt-3 text-center">
          <p className="text-danger">{s.loadFailed}</p>
          <Button variant="outline" className="mt-3" onClick={() => setReload((k) => k + 1)}>
            {s.retry}
          </Button>
        </div>
      )}
      {list && list !== "failed" && (
        <>
          {list.data.length === 0 ? (
            <p className="mt-3 rounded-control border border-border px-4 py-6 text-center text-muted">{s.empty}</p>
          ) : (
            <ul className="mt-3 divide-y divide-border rounded-control border border-border">
              <li className="hidden grid-cols-[6rem_8rem_1fr_7rem_6rem] gap-3 bg-background px-4 py-2 text-xs font-semibold text-muted uppercase md:grid">
                <span>{s.colDate}</span>
                <span>{s.colType}</span>
                <span>{s.colDetails}</span>
                <span className="text-right">{s.colAmount}</span>
                <span />
              </li>
              {list.data.map((e) => (
                <li key={e.id} className="grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 px-4 py-3 text-sm md:grid-cols-[6rem_8rem_1fr_7rem_6rem] md:items-center">
                  <span className="text-muted md:order-none">
                    #{e.id} · {formatDate(e.entry_date, locale)}
                  </span>
                  <span className={`text-right font-semibold md:order-4 ${e.effect < 0 ? "text-danger" : "text-success"}`}>{signed(e.effect)}</span>
                  <span className="md:order-2">
                    <span className="inline-flex rounded-full bg-border/60 px-2.5 py-0.5 text-xs font-semibold text-heading">{s.types[e.type]}</span>
                  </span>
                  <span className="col-span-2 min-w-0 break-words md:order-3 md:col-span-1">
                    {details(e).map((d) => (
                      <span key={d} className="block text-muted">
                        {d}
                      </span>
                    ))}
                    {e.reason && <span className="block text-heading">“{e.reason}”</span>}
                    {e.created_by && <span className="block text-xs text-muted">{e.created_by}</span>}
                  </span>
                  <span className="col-span-2 md:order-5 md:col-span-1 md:text-right">
                    {e.can_reverse && (
                      <Button
                        variant="danger"
                        size="sm"
                        disabled={busy}
                        onClick={() => {
                          setFlash(null);
                          setFailure(null);
                          setReverseError(undefined);
                          setReverseOf(e);
                        }}
                      >
                        {s.reverse}
                      </Button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          )}
          {list.meta.last_page > 1 && (
            <div className="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                {s.prevPage}
              </Button>
              <span className="text-muted">{fill(s.page, { page: list.meta.current_page, last: list.meta.last_page })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                {s.nextPage}
              </Button>
            </div>
          )}
        </>
      )}

      {/* Reverse: ask for the reason first */}
      {reverseOf && (
        <Modal title={s.confirmReverseTitle} onClose={() => setReverseOf(null)}>
          <p>{fill(s.reverseIntro, { id: reverseOf.id, type: s.types[reverseOf.type], amount: taka(reverseOf.amount) })}</p>
          <Textarea
            className="mt-4"
            label={s.reason}
            rows={2}
            maxLength={500}
            placeholder={s.reasonPlaceholder}
            value={reverseReason}
            error={reverseError}
            onChange={(e) => {
              setReverseReason(e.target.value);
              setReverseError(undefined);
            }}
          />
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="outline" onClick={() => setReverseOf(null)}>
              {s.cancel}
            </Button>
            <Button onClick={checkReverse}>{s.adjustSubmit}</Button>
          </div>
        </Modal>
      )}

      {/* Confirm: amount + new balance before saving */}
      {pending && figures && (
        <Modal title={pending.kind === "adjust" ? s.confirmAdjustTitle : s.confirmReverseTitle} onClose={() => !busy && setPending(null)}>
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
            <dt className="text-muted">{s.confirmAmount}</dt>
            <dd className="text-right font-semibold">{signed(effectOf(pending))}</dd>
            <dt className="text-muted">{s.confirmBalanceNow}</dt>
            <dd className="text-right">{taka(figures.balance)}</dd>
            <dt className="text-muted">{s.confirmBalanceAfter}</dt>
            <dd className="text-right text-lg font-bold text-heading">{taka(figures.balance + effectOf(pending))}</dd>
            <dt className="text-muted">{s.confirmReason}</dt>
            <dd className="text-right break-words">{pending.reason}</dd>
          </dl>
          <Alert tone="warning" className="mt-4">
            {s.confirmNote}
          </Alert>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="outline" disabled={busy} onClick={() => setPending(null)}>
              {s.cancel}
            </Button>
            <Button disabled={busy} onClick={() => void save(pending)}>
              {busy ? s.saving : s.confirmSave}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}

/** Read-only integrity check (Phase 12b): lists mismatches, never fixes them. Runs on open and on "run again". */
export function IntegritySection({ onAuthError, version }: { onAuthError: (res: ApiResult<unknown>) => void; version: number }) {
  const t = useDictionary();
  const locale = useLocale();
  const s = t.fundSettings.integrity;
  const signed = useSignedTaka();
  const [result, setResult] = useState<IntegrityResult | null | "failed">(null);
  const [busy, setBusy] = useState(false);

  const run = useCallback(async () => {
    setBusy(true);
    const res = await apiFetch<{ data: IntegrityResult }>("/admin/ledger/integrity");
    setBusy(false);
    if (res.ok) setResult(res.data.data);
    else {
      onAuthError(res);
      setResult("failed");
    }
  }, [onAuthError]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect -- loads the check result from the API
    void run();
  }, [run, version]);

  const text = (issue: IntegrityResult["issues"][number]) => {
    const template = (s.codes as Record<string, string>)[issue.code] ?? issue.code;
    const values: Record<string, string | number> = {};
    for (const [k, v] of Object.entries(issue)) {
      if (v === null) values[k] = "—";
      else if (typeof v === "number" && ["amount", "expected"].includes(k)) values[k] = k === "amount" && v < 0 ? signed(v) : formatTaka(v, locale);
      else values[k] = v;
    }
    return fill(template, values);
  };

  return (
    <Card className="p-5 sm:p-6">
      <SectionTitle icon="checkCircle" title={s.title} subtitle={s.subtitle} />

      {result === null && <div className="mt-4 h-20 animate-pulse rounded-control bg-border/60" aria-busy="true" />}
      {result === "failed" && <Alert className="mt-4">{s.loadFailed}</Alert>}
      {result && result !== "failed" && (
        <div className="mt-4 space-y-3">
          {result.issues.length === 0 ? (
            <Alert tone="success">{fill(s.ok, { n: result.checked_entries })}</Alert>
          ) : (
            <>
              <Alert tone={result.ok ? "warning" : "danger"}>
                {fill(s.found, { n: result.issues.length, entries: result.checked_entries })}
              </Alert>
              <ul className="divide-y divide-border rounded-control border border-border">
                {result.issues.map((issue, i) => (
                  <li key={i} className="flex flex-wrap items-start gap-x-3 gap-y-1 px-4 py-3 text-sm">
                    <span
                      className={`inline-flex shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                        issue.severity === "problem" ? "bg-danger-soft text-danger" : "bg-warning-soft text-foreground"
                      }`}
                    >
                      {issue.severity === "problem" ? s.problem : s.note}
                    </span>
                    <span className="min-w-0 flex-1 break-words">{text(issue)}</span>
                  </li>
                ))}
              </ul>
              <p className="text-sm text-muted">{s.fixHint}</p>
            </>
          )}
          <p className="text-xs text-muted">{formatDate(result.checked_at, locale)}</p>
        </div>
      )}

      <Button variant="outline" className="mt-4" disabled={busy} onClick={() => void run()}>
        {busy ? s.running : s.run}
      </Button>
    </Card>
  );
}
