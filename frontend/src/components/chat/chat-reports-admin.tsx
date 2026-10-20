"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import type { Dictionary } from "@/i18n/config";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar } from "@/components/profile/profile-view";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type AdminChatReport, type ChatReportPerson, type ChatReportStatus, type Paginated } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { setOpenChatReports } from "./open-chat-reports";

type Tab = ChatReportStatus | "";
const TABS: Tab[] = ["open", "reviewed", "dismissed", ""];

type ListPage = Paginated<AdminChatReport> & { meta: { open_count: number } };
type Load = { key: string; ok: true; page: ListPage } | { key: string; ok: false };

/** /dashboard/chat-reports — reported chat messages (Phase 9d-1, admin + super_admin). Only the one reported message. */
export function ChatReportsScreen() {
  return <RequireAuth roles={navItem("chatReports").roles}>{() => <ChatReports />}</RequireAuth>;
}

const STATUS_STYLE: Record<ChatReportStatus, string> = {
  open: "bg-danger-soft text-danger",
  reviewed: "bg-success-soft text-success",
  dismissed: "bg-background text-muted",
};

function StatusBadge({ status, t }: { status: ChatReportStatus; t: Dictionary }) {
  return (
    <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATUS_STYLE[status]}`}>
      {t.chatReports.statuses[status]}
    </span>
  );
}

function ChatReports() {
  const t = useDictionary();
  const locale = useLocale();
  const r = t.chatReports;
  const [tab, setTab] = useState<Tab>("open");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  const [openId, setOpenId] = useState<number | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const digits = (n: number) => localizeDigits(String(n), locale);

  const key = `${tab}|${page}|${reloadKey}`;
  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (tab) params.set("status", tab);
    void apiFetch<ListPage>(`/admin/chat-reports?${params}`).then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
      if (res.ok) setOpenChatReports(res.data.meta.open_count);
    });
    return () => {
      cancelled = true;
    };
  }, [tab, page, key]);

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;

  return (
    <div className="space-y-5">
      <p className="max-w-3xl">{r.subtitle}</p>
      {flash && <Alert tone="success">{flash}</Alert>}

      <div role="group" aria-label={r.tabsLabel} className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
        {TABS.map((s) => {
          const active = s === tab;
          const count = s === "open" && list ? list.meta.open_count : null;
          return (
            <button
              key={s || "all"}
              type="button"
              aria-pressed={active}
              onClick={() => {
                setTab(s);
                setPage(1);
              }}
              className={`rounded-full border px-4 py-2.5 text-sm font-semibold transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {s ? r.statuses[s] : r.all}
              {count ? ` (${digits(count)})` : ""}
            </button>
          );
        })}
      </div>

      {load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{r.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {r.retry}
          </Button>
        </Card>
      ) : !list ? (
        <ul className="space-y-3" aria-busy="true">
          <li className="sr-only" role="status">
            {r.loading}
          </li>
          {[0, 1, 2].map((i) => (
            <li key={i} className="h-28 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : list.data.length === 0 ? (
        <Card className="text-center text-muted">{tab ? r.emptyFiltered : r.empty}</Card>
      ) : (
        <div className={stale ? "opacity-60 transition-opacity" : undefined}>
          <p className="mb-3 text-sm text-muted">{fill(r.total, { n: digits(list.meta.total) })}</p>
          <ul className="space-y-3">
            {list.data.map((rep) => (
              <li key={rep.id}>
                <Card className="p-4 sm:p-5">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar name={rep.reported_user.name} url={rep.reported_user.photo_url} className="size-11" rounded="rounded-full" />
                      <div className="min-w-0">
                        <p className="font-semibold break-words text-heading">{rep.reported_user.name}</p>
                        <p className="text-sm text-muted">
                          {t.chat.reasons[rep.reason]} · {formatDate(rep.created_at, locale)}
                        </p>
                      </div>
                    </div>
                    <StatusBadge status={rep.status} t={t} />
                  </div>
                  <p className="mt-3 line-clamp-2 rounded-control bg-background px-3 py-2 text-sm wrap-anywhere text-foreground">
                    {rep.message_snapshot}
                  </p>
                  <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm text-muted">
                      {r.reporter}: {rep.reporter.name}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setFlash(null);
                        setOpenId(rep.id);
                      }}
                    >
                      {r.open}
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
                {r.prev}
              </Button>
              <span className="text-sm text-muted">{fill(r.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {r.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}

      {openId !== null && (
        <ReportDetail
          id={openId}
          onClose={() => setOpenId(null)}
          onDone={(message, openCount) => {
            setOpenId(null);
            setFlash(message);
            setOpenChatReports(openCount);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

type DetailLoad = { ok: true; data: AdminChatReport } | { ok: false; text: string };

/** One report: the message copy, reason, note, both members (with a link to the admin profile), Reviewed / Dismiss. */
function ReportDetail({ id, onClose, onDone }: { id: number; onClose: () => void; onDone: (message: string, openCount: number) => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const r = t.chatReports;
  const [load, setLoad] = useState<DetailLoad | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: AdminChatReport }>(`/admin/chat-reports/${id}`).then((res) => {
      if (!cancelled) setLoad(res.ok ? { ok: true, data: res.data.data } : { ok: false, text: failureText(res, t) });
    });
    return () => {
      cancelled = true;
    };
  }, [id, t]);

  const decide = async (action: "review" | "dismiss") => {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ message: string; meta: { open_count: number } }>(`/admin/chat-reports/${id}/${action}`, { method: "POST" });
    setBusy(false);
    if (!res.ok) return setError(failureText(res, t));
    onDone(res.data.message, res.data.meta.open_count);
  };

  const rep = load?.ok ? load.data : null;

  return (
    <Modal title={r.message} onClose={busy ? () => {} : onClose}>
      {!load ? (
        <p role="status" className="py-6 text-center text-muted">
          {r.loading}
        </p>
      ) : !rep ? (
        <Alert tone="danger">{!load.ok ? load.text : ""}</Alert>
      ) : (
        <div className="space-y-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm text-muted">
              {r.date}: {formatDate(rep.created_at, locale)}
            </span>
            <StatusBadge status={rep.status} t={t} />
          </div>
          <blockquote className="max-h-56 overflow-y-auto rounded-control border-l-4 border-danger/50 bg-background px-3 py-2.5 wrap-anywhere whitespace-pre-line text-foreground">
            {rep.message_snapshot}
          </blockquote>
          <p className="flex gap-2 text-xs text-muted">
            <Icon name="lock" className="size-4 shrink-0" />
            {r.privacy}
          </p>
          <dl className="grid gap-3 text-sm sm:grid-cols-2">
            <div>
              <dt className="text-xs text-muted">{r.reason}</dt>
              <dd className="font-medium text-heading">{t.chat.reasons[rep.reason]}</dd>
            </div>
            <div className="sm:col-span-2">
              <dt className="text-xs text-muted">{r.note}</dt>
              <dd className={`wrap-anywhere whitespace-pre-line ${rep.note ? "text-heading" : "text-muted italic"}`}>{rep.note ?? r.noNote}</dd>
            </div>
          </dl>
          <PersonRow label={r.reportedUser} person={rep.reported_user} t={t} />
          <PersonRow label={r.reporter} person={rep.reporter} t={t} />
          {rep.reviewed_by && (
            <p className="text-sm text-muted">{fill(r.reviewedBy, { name: rep.reviewed_by.name, date: formatDate(rep.reviewed_at, locale) })}</p>
          )}
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="flex flex-wrap justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={onClose} disabled={busy}>
              {r.close}
            </Button>
            {rep.status === "open" && (
              <>
                <Button variant="outline" onClick={() => void decide("dismiss")} disabled={busy}>
                  {busy ? r.working : r.dismiss}
                </Button>
                <Button onClick={() => void decide("review")} disabled={busy}>
                  <Icon name="checkCircle" className="size-4" />
                  {busy ? r.working : r.markReviewed}
                </Button>
              </>
            )}
          </div>
        </div>
      )}
      {load && !load.ok && (
        <div className="mt-4 flex justify-end">
          <Button variant="ghost" onClick={onClose}>
            {r.close}
          </Button>
        </div>
      )}
    </Modal>
  );
}

function PersonRow({ label, person, t }: { label: string; person: ChatReportPerson; t: Dictionary }) {
  const locale = useLocale();
  return (
    <div className="flex items-center gap-3 rounded-control border border-border px-3 py-2.5">
      <Avatar name={person.name} url={person.photo_url} className="size-10" rounded="rounded-full" />
      <div className="min-w-0 flex-1">
        <p className="text-xs text-muted">{label}</p>
        <p className="truncate font-semibold text-heading">{person.name}</p>
        <p className="text-xs text-muted">
          {localizeDigits(person.phone, locale)} · {t.statuses[person.status]}
        </p>
      </div>
      <Link
        href={`/dashboard/programmers/${person.id}`}
        className="flex shrink-0 items-center gap-1 p-1.5 text-sm font-semibold text-primary-text hover:underline sm:p-0"
        title={t.chatReports.viewProfile}
      >
        {/* Icon only on a phone, so the name keeps its width (9d-2). */}
        <span className="sr-only sm:not-sr-only">{t.chatReports.viewProfile}</span>
        <Icon name="external" className="size-5 sm:size-3.5" />
      </Link>
    </div>
  );
}
