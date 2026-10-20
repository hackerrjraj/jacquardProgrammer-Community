"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { METHODS } from "@/components/payments/payments-shared";
import { Avatar } from "@/components/profile/profile-view";
import { failureText, formatSize } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import {
  apiDownload,
  apiFetch,
  type AdminAidRequest,
  type AidStatus,
  type ApiResult,
  type Paginated,
  type PaymentMethod,
  type User,
} from "@/lib/api";
import { fill, formatDate, toAsciiDigits } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";
import { AidBadge, AMOUNT_MAX, downloadPrivateFile, FILE_MAX_BYTES, FILE_TYPES, reasonText } from "./assistance-shared";

type Tab = AidStatus | "";
const TABS: Tab[] = ["", "submitted", "approved", "paid", "rejected", "cancelled"];
const NOTE_MAX = 500;
const TXID_MAX = 50;
const TXID_RE = /^[A-Z0-9\-/]+$/;

type ListPage = Paginated<AdminAidRequest> & { meta: { fund_balance: number } };
type Load = { key: string; ok: true; page: ListPage } | { key: string; ok: false };

/** /dashboard/aid — aid applications: list, case detail, approve / reject, payout (Phase 8c, admin + super_admin). */
export function AidAdminScreen() {
  return <RequireAuth roles={navItem("aid").roles}>{(me) => <AidAdmin me={me} />}</RequireAuth>;
}

/** Today in the browser's time zone as YYYY-MM-DD (the API refuses a future payout date). */
const today = () => new Date().toLocaleDateString("en-CA");

function AidAdmin({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.aidAdmin;
  const [tab, setTab] = useState<Tab>("");
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const digits = (n: number) => localizeDigits(String(n), locale);
  const taka = (n: number) => formatTaka(n, locale);

  // Search: wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [search]);

  const key = `${tab}|${query}|${page}|${reloadKey}`;
  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (tab) params.set("status", tab);
    if (query) params.set("search", query);
    void apiFetch<ListPage>(`/admin/assistance?${params}`).then((res) => {
      if (!cancelled) setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [tab, query, page, reloadKey, key]);

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;

  return (
    <div className="space-y-5">
      <p className="max-w-3xl">{a.subtitle}</p>
      {flash && <Alert tone="success">{flash}</Alert>}

      <Card className="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <div>
          <p className="text-sm text-muted">{a.fundBalance}</p>
          {load && !load.ok && !list ? (
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-medium text-danger">{a.balanceFailed}</p>
              <Button variant="outline" size="sm" onClick={() => setReloadKey((k) => k + 1)}>
                {a.retry}
              </Button>
            </div>
          ) : (
            <p className="font-heading text-2xl font-bold text-heading">{list ? taka(list.meta.fund_balance) : "…"}</p>
          )}
        </div>
        <p className="max-w-md text-sm text-muted">{a.balanceHint}</p>
      </Card>

      <Card className="space-y-4 p-4 sm:p-5">
        <div role="group" aria-label={a.tabsLabel} className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          {TABS.map((s) => {
            const active = s === tab;
            return (
              <button
                key={s || "all"}
                type="button"
                aria-pressed={active}
                onClick={() => {
                  setTab(s);
                  setPage(1);
                }}
                className={`rounded-full border px-3 py-2.5 text-sm font-semibold transition-colors ${
                  active
                    ? "border-secondary bg-secondary text-secondary-foreground"
                    : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
                }`}
              >
                {s ? t.assistance.statuses[s] : a.all}
              </button>
            );
          })}
        </div>
        <form role="search" onSubmit={(e) => e.preventDefault()} className="max-w-xl">
          <Input
            label={a.search}
            type="search"
            placeholder={a.searchPlaceholder}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            maxLength={100}
          />
        </form>
      </Card>

      {load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {a.retry}
          </Button>
        </Card>
      ) : !list ? (
        <ul className="space-y-3" aria-busy="true">
          <li className="sr-only" role="status">
            {a.loading}
          </li>
          {[0, 1, 2].map((i) => (
            <li key={i} className="h-32 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : list.data.length === 0 ? (
        <Card className="text-center text-muted">{query || tab ? a.emptyFiltered : a.empty}</Card>
      ) : (
        <div className={stale ? "opacity-60 transition-opacity" : undefined}>
          <p className="mb-3 text-sm text-muted">{fill(a.total, { n: digits(list.meta.total) })}</p>
          <ul className="space-y-3">
            {list.data.map((r) => (
              <li key={r.id}>
                <Card className="p-4 sm:p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={r.applicant.name} url={r.applicant.photo_url} className="size-11" rounded="rounded-full" />
                      <div className="min-w-0">
                        <p className="font-semibold break-words text-heading">{r.applicant.name}</p>
                        <p className="text-sm text-muted">
                          <span className="font-mono">{r.case_id}</span> · {formatDate(r.created_at, locale)}
                        </p>
                      </div>
                    </div>
                    <AidBadge status={r.status} t={t} />
                  </div>
                  <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
                    {detail(a.reason, reasonText(r, t))}
                    {detail(a.requested, taka(r.amount_requested))}
                    {detail(a.approved, r.amount_approved !== null ? taka(r.amount_approved) : "—")}
                    {detail(a.votes, fill(a.votesLine, { yes: digits(r.votes.yes), no: digits(r.votes.no) }))}
                  </dl>
                  <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                    {r.applicant.id === me.id ? <span className="text-sm font-semibold text-warning">{a.ownBadge}</span> : <span />}
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setFlash(null);
                        setOpenId(r.id);
                      }}
                    >
                      {a.open}
                      <Icon name="chevronRight" className="size-4" />
                    </Button>
                  </div>
                </Card>
              </li>
            ))}
          </ul>
          {list.meta.last_page > 1 && (
            <nav className="mt-5 flex items-center justify-between gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1 || stale} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {a.prev}
              </Button>
              <span className="text-sm text-muted">{fill(a.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {a.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}

      {openId !== null && (
        <CaseDialog
          id={openId}
          me={me}
          onClose={() => setOpenId(null)}
          onDone={(message) => {
            setOpenId(null);
            setFlash(message);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

const detail = (label: string, value: React.ReactNode, wide = false) => (
  <div className={`min-w-0 ${wide ? "col-span-2 sm:col-span-4" : ""}`}>
    <dt className="text-xs text-muted">{label}</dt>
    <dd className="font-medium break-words text-heading">{value}</dd>
  </div>
);

type CaseLoad = { ok: true; data: AdminAidRequest; balance: number } | { ok: false; text: string };
type Mode = "view" | "approve" | "reject" | "pay";

/** One case: full details, files, who voted how, and the action that fits its status. */
function CaseDialog({ id, me, onClose, onDone }: { id: number; me: User; onClose: () => void; onDone: (message: string) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.aidAdmin;
  const [load, setLoad] = useState<CaseLoad | null>(null);
  const [mode, setMode] = useState<Mode>("view");
  const [busy, setBusy] = useState(false);
  const [downloadError, setDownloadError] = useState(false);
  const digits = (n: number) => localizeDigits(String(n), locale);
  const taka = (n: number) => formatTaka(n, locale);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: AdminAidRequest; meta: { fund_balance: number } }>(`/admin/assistance/${id}`).then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { ok: true, data: res.data.data, balance: res.data.meta.fund_balance } : { ok: false, text: failureText(res, t) });
    });
    return () => {
      cancelled = true;
    };
  }, [id, t]);

  const close = () => {
    if (!busy) onClose();
  };
  const save = async (status: Promise<number>) => {
    setDownloadError(false);
    if ((await status) !== 200) setDownloadError(true);
  };

  if (!load)
    return (
      <Modal title={a.caseTitle} onClose={close}>
        <p role="status" className="py-8 text-center text-muted">
          {a.loading}
        </p>
      </Modal>
    );
  if (!load.ok)
    return (
      <Modal title={a.caseTitle} onClose={close}>
        <Alert tone="danger">{load.text}</Alert>
        <div className="mt-5 flex justify-end">
          <Button variant="outline" onClick={close}>
            {a.close}
          </Button>
        </div>
      </Modal>
    );

  const r = load.data;
  const own = r.applicant.id === me.id;
  const yes = r.voters?.filter((v) => v.vote === "yes") ?? [];
  const no = r.voters?.filter((v) => v.vote === "no") ?? [];
  const voterList = (label: string, items: typeof yes, tone: string) => (
    <div className="min-w-0">
      <p className={`text-xs font-semibold ${tone}`}>
        {label} ({digits(items.length)})
      </p>
      {items.length === 0 ? (
        <p className="text-muted">—</p>
      ) : (
        <ul className="mt-1 space-y-0.5">
          {items.map((v, i) => (
            <li key={v.user?.id ?? i} className="break-words text-heading">
              {v.user?.name ?? a.deletedUser}
              {v.voted_at && <span className="text-xs text-muted"> · {formatDate(v.voted_at, locale)}</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );

  return (
    <Modal title={fill(a.caseTitleId, { id: r.case_id })} onClose={close}>
      <div className="space-y-4 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div className="flex min-w-0 items-center gap-3">
            <Avatar name={r.applicant.name} url={r.applicant.photo_url} className="size-10" rounded="rounded-full" />
            <div className="min-w-0">
              <Link href={`/dashboard/programmers/${r.applicant.id}`} className="font-semibold break-words text-heading hover:text-primary-text">
                {r.applicant.name}
              </Link>
              <p className="text-muted">{r.applicant.phone ?? "—"}</p>
            </div>
          </div>
          <AidBadge status={r.status} t={t} />
        </div>

        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
          {detail(a.reason, reasonText(r, t))}
          {detail(a.requested, taka(r.amount_requested))}
          {detail(a.approved, r.amount_approved !== null ? taka(r.amount_approved) : "—")}
          {detail(a.submitted, formatDate(r.created_at, locale) || "—")}
          {r.applying_for && detail(a.applyingFor, `${r.applying_for.name} (${r.applying_for.relation})`, true)}
          {detail(a.description, <span className="font-normal whitespace-pre-line">{r.description}</span>, true)}
        </dl>

        <div>
          <p className="text-xs text-muted">{a.files}</p>
          {r.documents.length === 0 ? (
            <p className="text-muted">{a.noFiles}</p>
          ) : (
            <ul className="mt-1 space-y-2">
              {r.documents.map((d) => (
                <li key={d.id} className="flex flex-wrap items-center gap-2">
                  <Icon name={d.mime === "application/pdf" ? "fileText" : "image"} className="size-4 shrink-0 text-muted" />
                  <span className="min-w-0 flex-1 truncate text-heading" title={d.name}>
                    {d.name} <span className="text-muted">· {formatSize(d.size, locale)}</span>
                  </span>
                  <Button variant="outline" size="sm" className="h-8 px-3" onClick={() => void save(apiDownload(d.url, d.name))}>
                    <Icon name="download" className="size-4" />
                    {a.download}
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-control bg-background p-3">
          <p className="text-xs text-muted">{a.whoVoted}</p>
          <div className="mt-2 grid grid-cols-2 gap-4">
            {voterList(a.voteYes, yes, "text-success")}
            {voterList(a.voteNo, no, "text-danger")}
          </div>
          <p className="mt-2 text-xs text-muted">{a.votesAdvisory}</p>
        </div>

        {(r.decided_by || r.rejection_reason || r.decision_note) && (
          <div className="space-y-1">
            {r.decided_by && <p className="text-muted">{fill(a.decidedBy, { name: r.decided_by.name, date: formatDate(r.decided_at, locale) })}</p>}
            {r.rejection_reason && (
              <p className="rounded-control bg-danger-soft px-3 py-2 text-danger">
                <span className="font-semibold">{a.rejectReason}:</span> {r.rejection_reason}
              </p>
            )}
            {r.decision_note && (
              <p>
                <span className="font-semibold">{a.note}:</span> {r.decision_note}
              </p>
            )}
          </div>
        )}

        {r.payout && (
          <div className="rounded-control border border-success/30 bg-success-soft/50 p-3">
            <p className="font-semibold text-success">{a.payoutDone}</p>
            <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
              {detail(a.method, t.payments.methods[r.payout.method])}
              {detail(a.payoutDate, r.payout.date ? formatDate(r.payout.date, locale) : "—")}
              {detail(a.txid, r.payout.transaction_id ? <span className="font-mono">{r.payout.transaction_id}</span> : "—")}
              {detail(a.paidBy, r.paid_by?.name ?? "—")}
              {r.payout.note && detail(a.note, r.payout.note, true)}
            </dl>
            {r.payout.proof_url && (
              <Button
                variant="outline"
                size="sm"
                className="mt-3"
                onClick={() => void save(downloadPrivateFile(r.payout!.proof_url!, `${r.case_id}-payout-proof`))}
              >
                <Icon name="download" className="size-4" />
                {a.payoutProof}
              </Button>
            )}
          </div>
        )}

        {downloadError && <Alert tone="danger">{a.downloadFailed}</Alert>}

        {own && (r.status === "submitted" || r.status === "approved") && <Alert tone="warning">{a.ownNote}</Alert>}

        {!own && mode === "approve" && (
          <ApproveForm r={r} busy={busy} setBusy={setBusy} onCancel={() => setMode("view")} onDone={onDone} />
        )}
        {!own && mode === "reject" && <RejectForm r={r} busy={busy} setBusy={setBusy} onCancel={() => setMode("view")} onDone={onDone} />}
        {!own && mode === "pay" && (
          <PayForm r={r} balance={load.balance} busy={busy} setBusy={setBusy} onCancel={() => setMode("view")} onDone={onDone} />
        )}

        {mode === "view" && (
          <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
            <Button variant="outline" onClick={close}>
              {a.close}
            </Button>
            {!own && r.status === "submitted" && (
              <>
                <Button variant="outline" className="border-danger text-danger" onClick={() => setMode("reject")}>
                  {a.reject}
                </Button>
                <Button onClick={() => setMode("approve")}>{a.approve}</Button>
              </>
            )}
            {!own && r.status === "approved" && <Button onClick={() => setMode("pay")}>{a.recordPayout}</Button>}
          </div>
        )}
      </div>
    </Modal>
  );
}

type FormProps = {
  r: AdminAidRequest;
  busy: boolean;
  setBusy: (b: boolean) => void;
  onCancel: () => void;
  onDone: (message: string) => void;
};

/** Shared bottom row + error for the three action forms. */
function FormButtons({ busy, confirm, onCancel, disabled }: { busy: boolean; confirm: string; onCancel: () => void; disabled?: boolean }) {
  const a = useDictionary().aidAdmin;
  return (
    <div className="flex flex-wrap justify-end gap-2">
      <Button type="button" variant="outline" disabled={busy} onClick={onCancel}>
        {a.back}
      </Button>
      <Button type="submit" disabled={busy || disabled}>
        {busy ? a.working : confirm}
      </Button>
    </div>
  );
}

const messageOf = (res: ApiResult<{ message?: string }>, fallback: string) => (res.ok ? (res.data.message ?? fallback) : "");

function ApproveForm({ r, busy, setBusy, onCancel, onDone }: FormProps) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.aidAdmin;
  const [amount, setAmount] = useState(String(r.amount_requested));
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const ascii = toAsciiDigits(amount).trim();
    const value = Number(ascii);
    if (!/^\d+$/.test(ascii) || value < 1 || value > AMOUNT_MAX) {
      setFieldError(fill(a.amountError, { max: formatTaka(AMOUNT_MAX, locale) }));
      return;
    }
    setFieldError(undefined);
    setError(null);
    setBusy(true);
    const res = await apiFetch<{ message?: string }>(`/admin/assistance/${r.id}/approve`, {
      method: "POST",
      body: { amount_approved: value, note: note.trim() || null },
    });
    setBusy(false);
    if (res.ok) onDone(messageOf(res, a.approvedDone));
    else setError(res.data?.errors?.amount_approved?.[0] ?? failureText(res, t));
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3 border-t border-border pt-4">
      <h3 className="font-semibold text-heading">{a.approveTitle}</h3>
      <Input
        label={a.amountLabel}
        inputMode="numeric"
        value={amount}
        onChange={(e) => setAmount(e.target.value)}
        error={fieldError}
      />
      <p className="text-xs text-muted">{fill(a.amountHint, { amount: formatTaka(r.amount_requested, locale) })}</p>
      <Textarea label={a.noteOptional} value={note} onChange={(e) => setNote(e.target.value)} maxLength={NOTE_MAX} rows={2} />
      {error && <Alert tone="danger">{error}</Alert>}
      <FormButtons busy={busy} confirm={a.approveConfirm} onCancel={onCancel} />
    </form>
  );
}

function RejectForm({ r, busy, setBusy, onCancel, onDone }: FormProps) {
  const t = useDictionary();
  const a = t.aidAdmin;
  const [reason, setReason] = useState("");
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | undefined>();

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!reason.trim()) {
      setFieldError(a.reasonRequired);
      return;
    }
    setFieldError(undefined);
    setError(null);
    setBusy(true);
    const res = await apiFetch<{ message?: string }>(`/admin/assistance/${r.id}/reject`, {
      method: "POST",
      body: { reason: reason.trim(), note: note.trim() || null },
    });
    setBusy(false);
    if (res.ok) onDone(messageOf(res, a.rejectedDone));
    else setError(res.data?.errors?.reason?.[0] ?? failureText(res, t));
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3 border-t border-border pt-4">
      <h3 className="font-semibold text-heading">{a.rejectTitle}</h3>
      <Textarea
        label={a.reasonLabel}
        placeholder={a.reasonPlaceholder}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        maxLength={NOTE_MAX}
        rows={3}
        error={fieldError}
      />
      <p className="text-xs text-muted">{a.reasonVisible}</p>
      <Textarea label={a.noteOptional} value={note} onChange={(e) => setNote(e.target.value)} maxLength={NOTE_MAX} rows={2} />
      {error && <Alert tone="danger">{error}</Alert>}
      <FormButtons busy={busy} confirm={a.rejectConfirm} onCancel={onCancel} />
    </form>
  );
}

function PayForm({ r, balance, busy, setBusy, onCancel, onDone }: FormProps & { balance: number }) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.aidAdmin;
  const amount = r.amount_approved ?? 0;
  const [method, setMethod] = useState<PaymentMethod | "">("");
  const [date, setDate] = useState(today());
  const [txid, setTxid] = useState("");
  const [note, setNote] = useState("");
  const [proof, setProof] = useState<File | null>(null);
  const crop = useImageCrop();
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const tooLow = balance < amount;

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const next: Record<string, string> = {};
    const cleanTxid = txid.replace(/\s+/g, "").toUpperCase();
    if (!method) next.method = a.methodRequired;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) next.payout_date = a.dateRequired;
    else if (date > today()) next.payout_date = a.dateFuture;
    if (cleanTxid && (cleanTxid.length > TXID_MAX || !TXID_RE.test(cleanTxid))) next.transaction_id = a.txidError;
    if (proof && (!FILE_TYPES.includes(proof.type) || proof.size > FILE_MAX_BYTES)) next.payout_proof = a.proofError;
    setErrors(next);
    if (Object.keys(next).length || tooLow) return;

    const body = new FormData();
    body.append("method", method);
    body.append("payout_date", date);
    if (cleanTxid) body.append("transaction_id", cleanTxid);
    if (note.trim()) body.append("note", note.trim());
    if (proof) body.append("payout_proof", proof);
    setError(null);
    setBusy(true);
    const res = await apiFetch<{ message?: string }>(`/admin/assistance/${r.id}/pay`, { method: "POST", body });
    setBusy(false);
    if (res.ok) return onDone(messageOf(res, a.paidDone));
    const fieldErrors = res.ok ? undefined : res.data?.errors;
    if (fieldErrors) setErrors(Object.fromEntries(Object.entries(fieldErrors).map(([k, v]) => [k, v[0]])));
    setError(failureText(res, t));
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-3 border-t border-border pt-4">
      <h3 className="font-semibold text-heading">{a.payTitle}</h3>
      <dl className="grid grid-cols-2 gap-3">
        <div className="rounded-control border border-border bg-background px-3 py-2">
          <dt className="text-xs text-muted">{a.payAmount}</dt>
          <dd className="font-heading text-lg font-bold text-heading">{formatTaka(amount, locale)}</dd>
        </div>
        <div className={`rounded-control border px-3 py-2 ${tooLow ? "border-danger/40 bg-danger-soft" : "border-border bg-background"}`}>
          <dt className="text-xs text-muted">{a.fundBalance}</dt>
          <dd className={`font-heading text-lg font-bold ${tooLow ? "text-danger" : "text-heading"}`}>{formatTaka(balance, locale)}</dd>
        </div>
      </dl>
      {tooLow && (
        <Alert tone="danger">{fill(a.balanceLow, { balance: formatTaka(balance, locale), amount: formatTaka(amount, locale) })}</Alert>
      )}
      <div className="grid gap-3 sm:grid-cols-2">
        <Select label={a.method} value={method} onChange={(e) => setMethod(e.target.value as PaymentMethod | "")} error={errors.method}>
          <option value="">{a.choose}</option>
          {METHODS.map((m) => (
            <option key={m} value={m}>
              {t.payments.methods[m]}
            </option>
          ))}
        </Select>
        <Input
          label={a.payoutDate}
          type="date"
          max={today()}
          value={date}
          onChange={(e) => setDate(e.target.value)}
          error={errors.payout_date}
        />
      </div>
      <Input
        label={a.txidOptional}
        value={txid}
        onChange={(e) => setTxid(e.target.value)}
        maxLength={TXID_MAX + 10}
        error={errors.transaction_id}
        className="font-mono"
      />
      <Textarea label={a.noteOptional} value={note} onChange={(e) => setNote(e.target.value)} maxLength={NOTE_MAX} rows={2} />
      <Input
        label={a.proofOptional}
        type="file"
        accept={FILE_TYPES.join(",")}
        onChange={(e) => {
          const chosen = e.target.files?.[0];
          if (chosen) crop.pick(chosen, "document", setProof);
          e.target.value = "";
        }}
        error={errors.payout_proof}
      />
      {proof && <p className="text-sm text-muted">{proof.name}</p>}
      {crop.dialog}
      <p className="text-xs text-muted">{a.payWarn}</p>
      {error && <Alert tone="danger">{error}</Alert>}
      <FormButtons busy={busy} confirm={fill(a.payConfirm, { amount: formatTaka(amount, locale) })} onCancel={onCancel} disabled={tooLow} />
    </form>
  );
}
