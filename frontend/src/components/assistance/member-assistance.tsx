"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Avatar } from "@/components/profile/profile-view";
import { failureText, formatSize } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { apiDownload, apiFetch, type AidRequest, type AidVotes, type OpenAidRequest, type Paginated, type User } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { formatTaka, localizeDigits } from "@/lib/format";
import { AidForm } from "./aid-form";
import { AidBadge, downloadPrivateFile, isDemoAccount, OPEN_STATUSES, reasonText } from "./assistance-shared";

type Tab = "mine" | "open";
const TABS: Tab[] = ["mine", "open"];

/** /dashboard/assistance — own aid requests (apply, view, cancel) + advisory votes on others' open requests (MASTER_SPEC §29–§32). */
export function AssistanceScreen() {
  return <RequireAuth>{(user) => <Assistance me={user} />}</RequireAuth>;
}

function Assistance({ me }: { me: User }) {
  const t = useDictionary();
  const a = t.assistance;
  const [tab, setTab] = useState<Tab>("mine");
  const [reload, setReload] = useState(0);
  const [flash, setFlash] = useState<string | null>(null);
  // undefined = not known yet; null = no open request. The open request is always the newest one
  // (a new request cannot be sent while one is open), so page 1 of "My requests" tells us.
  const [openRequest, setOpenRequest] = useState<AidRequest | null | undefined>(undefined);
  const demo = isDemoAccount(me);

  const changed = (message: string) => {
    setFlash(message);
    setOpenRequest(undefined);
    setReload((k) => k + 1);
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  return (
    <div className="space-y-6">
      <p className="max-w-3xl">{a.subtitle}</p>

      <div role="group" aria-label={a.tabsLabel} className="grid grid-cols-2 gap-2 sm:max-w-md">
        {TABS.map((s) => {
          const active = s === tab;
          return (
            <button
              key={s}
              type="button"
              aria-pressed={active}
              onClick={() => {
                setTab(s);
                setFlash(null);
              }}
              className={`rounded-full border px-3 py-2.5 text-sm font-semibold transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {a.tabs[s]}
            </button>
          );
        })}
      </div>

      {flash && (
        <Alert tone="success" className="flex items-start gap-2">
          <Icon name="checkCircle" className="mt-0.5 size-4 shrink-0" />
          <span>{flash}</span>
        </Alert>
      )}

      {tab === "mine" ? (
        <>
          {demo ? (
            <Notice text={a.form.demoNotice} />
          ) : openRequest === undefined ? (
            <div aria-hidden="true" className="h-40 animate-pulse rounded-card bg-border/60" />
          ) : openRequest ? (
            <Notice text={fill(a.form.openNotice, { caseId: openRequest.case_id })} />
          ) : (
            // A fresh, empty form after every successful request.
            <AidForm key={`form-${reload}`} onSubmitted={changed} />
          )}
          <MyRequests
            key={`list-${reload}`}
            onFirstPage={(items) => setOpenRequest(items.find((r) => OPEN_STATUSES.includes(r.status)) ?? null)}
            onChanged={changed}
          />
        </>
      ) : (
        <OpenRequests me={me} />
      )}
    </div>
  );
}

function Notice({ text }: { text: string }) {
  const t = useDictionary();
  return (
    <Card className="flex items-start gap-3 p-5 sm:p-6">
      <Icon name="alert" className="mt-0.5 size-6 shrink-0 text-warning" />
      <div>
        <h2 className="text-lg font-semibold">{t.assistance.form.title}</h2>
        <p className="mt-1">{text}</p>
      </div>
    </Card>
  );
}

type Load<T> = { key: string; ok: true; items: T[]; page: number; last: number; total: number } | { key: string; ok: false };

function Pager({ page, last, onPage, disabled }: { page: number; last: number; onPage: (p: number) => void; disabled?: boolean }) {
  const t = useDictionary();
  const locale = useLocale();
  const m = t.assistance.mine;
  const digits = (v: number) => localizeDigits(String(v), locale);
  if (last <= 1) return null;
  return (
    <nav className="mt-5 flex items-center justify-between gap-2">
      <Button variant="outline" size="sm" disabled={page <= 1 || disabled} onClick={() => onPage(page - 1)}>
        <Icon name="chevronLeft" className="size-4" />
        {m.prevPage}
      </Button>
      <span className="text-sm text-muted">{fill(m.page, { page: digits(page), last: digits(last) })}</span>
      <Button variant="outline" size="sm" disabled={page >= last || disabled} onClick={() => onPage(page + 1)}>
        {m.nextPage}
        <Icon name="chevronRight" className="size-4" />
      </Button>
    </nav>
  );
}

function ListSkeleton({ height = "h-32" }: { height?: string }) {
  return (
    <ul className="mt-5 space-y-3" aria-busy="true">
      {[0, 1, 2].map((i) => (
        <li key={i} className={`${height} animate-pulse rounded-card bg-border/60`} />
      ))}
    </ul>
  );
}

const detail = (label: string, value: React.ReactNode, wide = false) => (
  <div className={`min-w-0 ${wide ? "col-span-2 sm:col-span-4" : ""}`}>
    <dt className="text-xs text-muted">{label}</dt>
    <dd className="font-medium break-words text-heading">{value}</dd>
  </div>
);

// ---- My requests ----

function MyRequests({ onFirstPage, onChanged }: { onFirstPage: (items: AidRequest[]) => void; onChanged: (message: string) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const m = t.assistance.mine;
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const [open, setOpen] = useState<number | null>(null);
  const [cancelling, setCancelling] = useState<AidRequest | null>(null);
  const requestKey = `${page}|${retry}`;
  const [loaded, setLoaded] = useState<Load<AidRequest> | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<AidRequest>>(`/assistance?page=${page}`).then((res) => {
      if (cancelled) return;
      if (res.ok) {
        const d = res.data;
        setLoaded({ key: requestKey, ok: true, items: d.data, page: d.meta.current_page, last: d.meta.last_page, total: d.meta.total });
        if (page === 1) onFirstPage(d.data);
      } else {
        setLoaded({ key: requestKey, ok: false });
        // Not known — show the form; the API still refuses a second open request.
        if (page === 1) onFirstPage([]);
      }
    });
    return () => {
      cancelled = true;
    };
    // onFirstPage is a new function on every render of the parent; the list only reloads on page / retry.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const digits = (v: number) => localizeDigits(String(v), locale);

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{m.title}</h2>
      <p role="status" className="mt-1 text-sm text-muted">
        {current?.ok ? fill(m.total, { n: digits(current.total) }) : !current ? t.assistance.loading : ""}
      </p>

      {!current ? (
        <ListSkeleton />
      ) : !current.ok ? (
        <div className="mt-5 text-center">
          <p className="text-danger">{m.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setRetry((k) => k + 1)}>
            {t.assistance.retry}
          </Button>
        </div>
      ) : current.items.length === 0 ? (
        <div className="mt-5 rounded-control border border-dashed border-border px-4 py-8 text-center">
          <Icon name="heart" className="mx-auto size-8 text-muted" />
          <p className="mt-2 text-muted">{m.empty}</p>
        </div>
      ) : (
        <>
          <ul className="mt-5 space-y-3">
            {current.items.map((r) => (
              <li key={r.id} className="rounded-card border border-border p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-mono text-sm text-muted">{r.case_id}</p>
                    <p className="font-heading text-xl font-bold text-heading">{formatTaka(r.amount_requested, locale)}</p>
                  </div>
                  <AidBadge status={r.status} t={t} />
                </div>
                <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                  {detail(m.reason, reasonText(r, t))}
                  {detail(m.submitted, formatDate(r.created_at, locale))}
                  {r.amount_approved !== null && detail(m.approvedAmount, formatTaka(r.amount_approved, locale))}
                  {r.decided_at && detail(m.decided, formatDate(r.decided_at, locale))}
                  {r.cancelled_at && detail(m.cancelledOn, formatDate(r.cancelled_at, locale))}
                </dl>
                {r.status === "rejected" && r.rejection_reason && (
                  <Alert tone="danger" className="mt-3">
                    <span className="font-semibold">{m.rejectReason}</span> {r.rejection_reason}
                  </Alert>
                )}
                {r.status === "approved" && <p className="mt-3 text-sm text-muted">{m.waitingPayout}</p>}

                {open === r.id && <RequestDetails r={r} />}

                <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                  <Button variant="outline" size="sm" aria-expanded={open === r.id} onClick={() => setOpen(open === r.id ? null : r.id)}>
                    <Icon name={open === r.id ? "eyeOff" : "eye"} className="size-4" />
                    {open === r.id ? m.hideDetails : m.showDetails}
                  </Button>
                  {r.status === "submitted" && (
                    <Button variant="danger" size="sm" className="sm:ml-auto" onClick={() => setCancelling(r)}>
                      <Icon name="close" className="size-4" />
                      {m.cancel}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          <Pager page={page} last={current.last} onPage={setPage} />
        </>
      )}

      {cancelling && (
        <CancelDialog
          r={cancelling}
          onClose={() => setCancelling(null)}
          onDone={(message) => {
            setCancelling(null);
            onChanged(message);
          }}
          onStale={() => setRetry((k) => k + 1)}
        />
      )}
    </Card>
  );
}

function RequestDetails({ r }: { r: AidRequest }) {
  const t = useDictionary();
  const locale = useLocale();
  const m = t.assistance.mine;
  const [downloadError, setDownloadError] = useState(false);
  const digits = (v: number) => localizeDigits(String(v), locale);

  const save = async (status: Promise<number>) => {
    setDownloadError(false);
    if ((await status) !== 200) setDownloadError(true);
  };

  return (
    <div className="mt-4 space-y-4 rounded-control bg-background p-4 text-sm">
      <div>
        <p className="text-xs text-muted">{m.description}</p>
        <p className="mt-0.5 break-words whitespace-pre-line text-heading">{r.description}</p>
      </div>
      {r.applying_for && (
        <div>
          <p className="text-xs text-muted">{m.applyingFor}</p>
          <p className="font-medium text-heading">
            {r.applying_for.name} ({r.applying_for.relation})
          </p>
        </div>
      )}

      <div>
        <p className="text-xs text-muted">{m.votes}</p>
        <p className="font-medium text-heading">{fill(m.votesLine, { yes: digits(r.votes.yes), no: digits(r.votes.no) })}</p>
        <p className="text-xs text-muted">{m.votesNote}</p>
      </div>

      <div>
        <p className="text-xs text-muted">{m.files}</p>
        {r.documents.length === 0 ? (
          <p className="text-muted">{m.noFiles}</p>
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
                  {m.download}
                </Button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {r.decision_note && (
        <p>
          <span className="font-semibold">{m.decisionNote}</span> {r.decision_note}
        </p>
      )}

      {r.payout && (
        <div className="rounded-control border border-success/30 bg-success-soft/50 p-3">
          <p className="font-semibold text-success">{m.payoutTitle}</p>
          <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-4">
            {detail(m.approvedAmount, r.amount_approved !== null ? formatTaka(r.amount_approved, locale) : "—")}
            {detail(m.payoutMethod, t.payments.methods[r.payout.method])}
            {detail(m.payoutDate, r.payout.date ? formatDate(r.payout.date, locale) : "—")}
            {detail(
              m.payoutTxid,
              r.payout.transaction_id ? <span className="font-mono text-[0.95em]">{r.payout.transaction_id}</span> : "—",
            )}
            {r.payout.note && detail(m.payoutNote, r.payout.note, true)}
          </dl>
          {r.payout.proof_url && (
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={() => void save(downloadPrivateFile(r.payout!.proof_url!, `${r.case_id}-payout-proof`))}
            >
              <Icon name="download" className="size-4" />
              {m.payoutProof}
            </Button>
          )}
        </div>
      )}

      {downloadError && <Alert tone="danger">{m.downloadFailed}</Alert>}
    </div>
  );
}

function CancelDialog({
  r,
  onClose,
  onDone,
  onStale,
}: {
  r: AidRequest;
  onClose: () => void;
  onDone: (message: string) => void;
  onStale: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const m = t.assistance.mine;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function confirm() {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ message: string }>(`/assistance/${r.id}/cancel`, { method: "POST" });
    setBusy(false);
    if (res.ok) return onDone(res.data.message);
    setError(failureText(res, t));
    // Already decided meanwhile → show the new status behind the dialog.
    if (res.status === 422) onStale();
  }

  return (
    <Modal title={m.cancelTitle} onClose={() => !busy && onClose()}>
      <p>{fill(m.cancelText, { caseId: r.case_id, amount: formatTaka(r.amount_requested, locale) })}</p>
      {error && <Alert className="mt-4">{error}</Alert>}
      <div className="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="ghost" disabled={busy} onClick={onClose}>
          {m.keep}
        </Button>
        <Button variant="secondary" disabled={busy} onClick={() => void confirm()}>
          {busy ? m.working : m.cancelConfirm}
        </Button>
      </div>
    </Modal>
  );
}

// ---- Open requests (others', advisory votes) ----

function OpenRequests({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const o = t.assistance.open;
  const [page, setPage] = useState(1);
  const [retry, setRetry] = useState(0);
  const requestKey = `${page}|${retry}`;
  const [loaded, setLoaded] = useState<Load<OpenAidRequest> | null>(null);
  // Votes saved on this page (until the next reload).
  const [saved, setSaved] = useState<Record<number, { votes: AidVotes; my_vote: "yes" | "no" }>>({});
  const [busyId, setBusyId] = useState<number | null>(null);
  const [cardError, setCardError] = useState<{ id: number; text: string } | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Paginated<OpenAidRequest>>(`/assistance/open?page=${page}`).then((res) => {
      if (cancelled) return;
      if (res.ok) {
        const d = res.data;
        setLoaded({ key: requestKey, ok: true, items: d.data, page: d.meta.current_page, last: d.meta.last_page, total: d.meta.total });
        setSaved({});
      } else {
        setLoaded({ key: requestKey, ok: false });
      }
    });
    return () => {
      cancelled = true;
    };
  }, [page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const stale = !current && loaded?.ok === true;
  const shown = current ?? (loaded?.ok ? loaded : null);
  const digits = (v: number) => localizeDigits(String(v), locale);
  // The list also has the viewer's own open request — this section is for other members' requests.
  const items = shown?.ok ? shown.items.filter((r) => r.applicant.id !== me.id) : [];

  async function vote(r: OpenAidRequest, value: "yes" | "no") {
    setBusyId(r.id);
    setCardError(null);
    setNotice(null);
    const res = await apiFetch<{ message: string; data: { votes: AidVotes; my_vote: "yes" | "no" } }>(`/assistance/${r.id}/vote`, {
      method: "POST",
      body: { vote: value },
    });
    setBusyId(null);
    if (res.ok) {
      setSaved((prev) => ({ ...prev, [r.id]: res.data.data }));
      return;
    }
    if (res.status === 422 && !res.data?.errors) {
      // Decided or cancelled meanwhile → reload the list.
      setNotice(o.closed);
      setRetry((k) => k + 1);
      return;
    }
    setCardError({ id: r.id, text: failureText(res, t) });
  }

  return (
    <Card className="p-5 sm:p-6">
      <h2 className="text-lg font-semibold">{o.title}</h2>
      <p className="mt-1 text-sm text-muted">{o.subtitle}</p>

      {notice && (
        <Alert tone="warning" className="mt-4">
          {notice}
        </Alert>
      )}

      {!shown ? (
        current && !current.ok ? (
          <div className="mt-5 text-center">
            <p className="text-danger">{o.loadFailed}</p>
            <Button variant="outline" className="mt-4" onClick={() => setRetry((k) => k + 1)}>
              {t.assistance.retry}
            </Button>
          </div>
        ) : (
          <>
            <p role="status" className="sr-only">
              {t.assistance.loading}
            </p>
            <ListSkeleton height="h-48" />
          </>
        )
      ) : items.length === 0 ? (
        <div className="mt-5 rounded-control border border-dashed border-border px-4 py-8 text-center">
          <Icon name="vote" className="mx-auto size-8 text-muted" />
          <p className="mt-2 text-muted">{o.empty}</p>
        </div>
      ) : (
        <div aria-busy={stale} className={stale ? "opacity-60 transition-opacity" : "transition-opacity"}>
          <ul className="mt-5 space-y-4">
            {items.map((r) => {
              const votes = saved[r.id]?.votes ?? r.votes;
              const mine = saved[r.id]?.my_vote ?? r.my_vote;
              const busy = busyId === r.id;
              return (
                <li key={r.id} className="rounded-card border border-border p-4">
                  <div className="flex flex-wrap items-start gap-3">
                    <Avatar name={r.applicant.name} url={r.applicant.photo_url} className="size-12" rounded="rounded-full" />
                    <div className="min-w-0 flex-1">
                      <p className="font-semibold break-words text-heading">{r.applicant.name}</p>
                      <p className="text-sm text-muted">
                        {reasonText(r, t)} · {formatDate(r.created_at, locale)}
                      </p>
                    </div>
                    <p className="font-heading text-xl font-bold text-heading">{formatTaka(r.amount_requested, locale)}</p>
                  </div>

                  <p className="mt-3 text-sm break-words whitespace-pre-line text-foreground">{r.description}</p>
                  {r.applying_for && (
                    <p className="mt-2 text-sm">
                      <span className="text-muted">{t.assistance.mine.applyingFor}:</span>{" "}
                      <span className="font-medium text-heading">
                        {r.applying_for.name} ({r.applying_for.relation})
                      </span>
                    </p>
                  )}
                  <p className="mt-2 font-mono text-xs text-muted">{r.case_id}</p>

                  <div className="mt-4 border-t border-border pt-4">
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                      <span className="text-muted">{o.votes}</span>
                      <span className="rounded-full bg-success-soft px-2.5 py-0.5 font-semibold text-success">
                        {fill(o.yesCount, { n: digits(votes.yes) })}
                      </span>
                      <span className="rounded-full bg-danger-soft px-2.5 py-0.5 font-semibold text-danger">
                        {fill(o.noCount, { n: digits(votes.no) })}
                      </span>
                    </div>

                    {r.can_vote ? (
                      <div className="mt-3 flex flex-wrap items-center gap-2">
                        <p className="w-full text-sm sm:w-auto">
                          {mine ? (
                            <>
                              {o.myVote}{" "}
                              <span className={`font-semibold ${mine === "yes" ? "text-success" : "text-danger"}`}>
                                {mine === "yes" ? o.votedYes : o.votedNo}
                              </span>
                            </>
                          ) : (
                            <span className="text-muted">{o.notVoted}</span>
                          )}
                        </p>
                        <div className="flex w-full gap-2 sm:ml-auto sm:w-auto">
                          <Button
                            size="sm"
                            variant={mine === "yes" ? "primary" : "outline"}
                            aria-pressed={mine === "yes"}
                            className="flex-1 sm:flex-none"
                            disabled={busy || stale}
                            onClick={() => void vote(r, "yes")}
                          >
                            <Icon name="checkCircle" className="size-4" />
                            {o.voteYes}
                          </Button>
                          <Button
                            size="sm"
                            variant={mine === "no" ? "secondary" : "outline"}
                            aria-pressed={mine === "no"}
                            className="flex-1 sm:flex-none"
                            disabled={busy || stale}
                            onClick={() => void vote(r, "no")}
                          >
                            <Icon name="close" className="size-4" />
                            {o.voteNo}
                          </Button>
                        </div>
                        {busy && (
                          <p role="status" className="w-full text-sm text-muted">
                            {o.saving}
                          </p>
                        )}
                      </div>
                    ) : (
                      <p className="mt-3 text-sm text-muted">{o.cannotVote}</p>
                    )}
                    {cardError?.id === r.id && <Alert className="mt-3">{cardError.text}</Alert>}
                  </div>
                </li>
              );
            })}
          </ul>
          <Pager page={page} last={shown.ok ? shown.last : 1} onPage={setPage} disabled={stale} />
        </div>
      )}
    </Card>
  );
}
