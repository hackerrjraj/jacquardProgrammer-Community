"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { failureText } from "@/components/profile/profile-shared";
import { apiFetch, type AdminNotice, type Paginated, type Role } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { NoticeForm } from "./notice-form";
import { formatDateTime, noticeTitle } from "./notice-text";

// Notices management: admin + super_admin only (same as the gallery — the committee has no admin powers).
const ROLES: readonly Role[] = ["admin", "super_admin"];

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: string; ok: true; items: AdminNotice[]; page: number; last: number; total: number } | { key: string; ok: false };
type View = { mode: "list" } | { mode: "form"; notice: AdminNotice | null };

/** /dashboard/notices */
export function NoticesAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <NoticesAdmin />}</RequireAuth>;
}

function NoticesAdmin() {
  const t = useDictionary();
  const a = t.notices.admin;
  const [view, setView] = useState<View>({ mode: "list" });
  const [flash, setFlash] = useState<Flash>(null);
  const [reload, setReload] = useState(0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{a.subtitle}</p>
        <div className="flex flex-wrap gap-2">
          <ButtonLink href="/notices" variant="outline" size="sm" target="_blank" rel="noopener">
            <Icon name="eye" className="size-4" />
            {a.viewPublic}
          </ButtonLink>
          {view.mode === "list" && (
            <Button
              size="sm"
              onClick={() => {
                setFlash(null);
                setView({ mode: "form", notice: null });
              }}
            >
              <Icon name="plus" className="size-4" />
              {a.new}
            </Button>
          )}
        </div>
      </div>

      {view.mode === "form" ? (
        <NoticeForm
          key={view.notice?.id ?? "new"}
          notice={view.notice}
          onCancel={() => setView({ mode: "list" })}
          onDone={(message) => {
            setFlash({ tone: "success", text: message });
            setReload((k) => k + 1);
            setView({ mode: "list" });
          }}
        />
      ) : (
        <NoticeList
          reloadSignal={reload}
          flash={flash}
          setFlash={setFlash}
          onEdit={(notice) => {
            setFlash(null);
            setView({ mode: "form", notice });
          }}
        />
      )}
    </div>
  );
}

function NoticeList({
  reloadSignal,
  flash,
  setFlash,
  onEdit,
}: {
  reloadSignal: number;
  flash: Flash;
  setFlash: (f: Flash) => void;
  onEdit: (notice: AdminNotice) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.notices.admin;
  const n = t.notices;
  const digits = (v: number) => localizeDigits(String(v), locale);

  const [status, setStatus] = useState("");
  const [visibility, setVisibility] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${status}|${visibility}|${page}|${reloadKey}|${reloadSignal}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<AdminNotice | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (status) params.set("status", status);
    if (visibility) params.set("visibility", visibility);
    void apiFetch<Paginated<AdminNotice>>(`/admin/notices?${params}`).then((res) => {
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
  }, [status, visibility, page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const items = current?.ok ? current.items : [];

  const filter = (setter: (v: string) => void) => (value: string) => {
    setter(value);
    setPage(1);
    setFlash(null);
  };

  /** publish / unpublish / pin / unpin — then reload (the order can change). */
  async function act(item: AdminNotice, action: "publish" | "unpublish" | "pin" | "unpin") {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string }>(`/admin/notices/${item.id}/${action}`, { method: "POST" });
    setBusy(false);
    setFlash(res.ok ? { tone: "success", text: res.data.message } : { tone: "danger", text: failureText(res, t) });
    if (res.ok) setReloadKey((k) => k + 1);
  }

  async function remove(item: AdminNotice) {
    setBusy(true);
    setDeleteError(null);
    const res = await apiFetch<{ message: string }>(`/admin/notices/${item.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      setDeleteError(failureText(res, t));
      return;
    }
    setDeleting(null);
    setFlash({ tone: "success", text: res.data.message });
    if (items.length === 1 && page > 1) setPage(page - 1);
    else setReloadKey((k) => k + 1);
  }

  const statusBadge = (item: AdminNotice) =>
    !item.is_published ? (
      <span className="inline-flex items-center gap-1 rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold text-muted ring-1 ring-border">
        <Icon name="eyeOff" className="size-3.5" />
        {a.statusDraft}
      </span>
    ) : item.is_scheduled ? (
      <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-foreground">
        <Icon name="clock" className="size-3.5" />
        {a.statusScheduled}
      </span>
    ) : (
      <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success">
        <span className="size-1.5 rounded-full bg-success" />
        {a.statusPublished}
      </span>
    );

  const dateText = (item: AdminNotice) =>
    !item.published_at
      ? a.noDate
      : item.is_scheduled
        ? fill(a.scheduledFor, { date: formatDateTime(item.published_at, locale) })
        : formatDateTime(item.published_at, locale);

  const iconButton = (label: string, icon: IconName, onClick: () => void, danger = false) => (
    <Button variant={danger ? "danger" : "outline"} size="sm" className="px-2.5" aria-label={label} title={label} disabled={busy} onClick={onClick}>
      <Icon name={icon} className="size-4" />
    </Button>
  );

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold">{a.listTitle}</h2>
          <p role="status" className="mt-1 text-sm text-muted">
            {current?.ok ? fill(a.total, { n: digits(current.total) }) : !current ? n.loading : ""}
          </p>
        </div>
        <div className="grid w-full gap-3 sm:w-auto sm:grid-cols-2">
          <Select label={a.filterStatus} value={status} onChange={(e) => filter(setStatus)(e.target.value)} className="sm:w-44">
            <option value="">{a.all}</option>
            <option value="published">{a.statusPublished}</option>
            <option value="unpublished">{a.statusDraft}</option>
            <option value="scheduled">{a.statusScheduled}</option>
          </Select>
          <Select label={a.filterVisibility} value={visibility} onChange={(e) => filter(setVisibility)(e.target.value)} className="sm:w-44">
            <option value="">{a.all}</option>
            <option value="public">{a.visibilityPublic}</option>
            <option value="members">{a.visibilityMembers}</option>
          </Select>
        </div>
      </div>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {!current ? (
        <ul className="mt-5 space-y-3" aria-busy="true">
          {Array.from({ length: 4 }, (_, i) => (
            <li key={i} className="h-24 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : !current.ok ? (
        <div className="mt-5 text-center">
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {n.retry}
          </Button>
        </div>
      ) : items.length === 0 ? (
        <p className="mt-5 text-muted">{a.empty}</p>
      ) : (
        <>
          <ul className="mt-5 divide-y divide-border rounded-card border border-border">
            {items.map((item) => (
              <li key={item.id} className="flex flex-col gap-3 p-4 md:flex-row md:items-center">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-1.5">
                    {statusBadge(item)}
                    <span
                      className={`inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                        item.visibility === "members" ? "bg-primary-soft text-primary-text" : "bg-background text-muted ring-1 ring-border"
                      }`}
                    >
                      <Icon name={item.visibility === "members" ? "lock" : "globe"} className="size-3.5" />
                      {item.visibility === "members" ? a.visibilityMembers : a.visibilityPublic}
                    </span>
                    {item.is_pinned && (
                      <span className="inline-flex items-center gap-1 rounded-full bg-warning-soft px-2.5 py-0.5 text-xs font-semibold text-foreground">
                        <Icon name="pin" className="size-3.5" />
                        {n.pinned}
                      </span>
                    )}
                    {item.attachment && (
                      <span className="inline-flex items-center text-muted" title={item.attachment.name}>
                        <Icon name="paperclip" className="size-4" />
                        <span className="sr-only">{n.attachment}</span>
                      </span>
                    )}
                  </div>
                  <p className="mt-1.5 font-semibold break-words text-heading">{noticeTitle(item, locale)}</p>
                  <p className="mt-0.5 text-sm text-muted">{dateText(item)}</p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                  {iconButton(a.edit, "pencil", () => onEdit(item))}
                  {iconButton(item.is_published ? a.unpublish : a.publish, item.is_published ? "eyeOff" : "eye", () =>
                    void act(item, item.is_published ? "unpublish" : "publish"),
                  )}
                  {iconButton(item.is_pinned ? a.unpin : a.pin, "pin", () => void act(item, item.is_pinned ? "unpin" : "pin"))}
                  {iconButton(
                    a.delete,
                    "trash",
                    () => {
                      setDeleteError(null);
                      setDeleting(item);
                    },
                    true,
                  )}
                </div>
              </li>
            ))}
          </ul>
          {current.last > 1 && (
            <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
              <Button variant="outline" size="sm" disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {a.prevPage}
              </Button>
              <span className="text-sm text-muted">{fill(a.page, { page: digits(current.page), last: digits(current.last) })}</span>
              <Button variant="outline" size="sm" disabled={busy || page >= current.last} onClick={() => setPage(page + 1)}>
                {a.nextPage}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </div>
          )}
        </>
      )}

      {deleting && (
        <Modal title={a.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <p className="break-words">{fill(a.deleteText, { title: noticeTitle(deleting, locale) })}</p>
          {deleteError && (
            <Alert tone="danger" className="mt-4">
              {deleteError}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {a.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? a.saving : a.delete}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}
