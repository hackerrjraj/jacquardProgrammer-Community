"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { apiFetch, type AdminContactMessage, type Paginated, type User } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { formatWhen } from "./admin-format";
import { setUnreadContactMessages } from "./unread-contact-messages";

type Filter = "" | "unread" | "read";
type ListPage = Paginated<AdminContactMessage> & { meta: { unread_count: number } };
type Load = { key: string; ok: true; page: ListPage } | { key: string; ok: false };

/** /dashboard/messages — messages sent through the public Contact form (Phase 11a, admin + super_admin). */
export function ContactMessagesScreen() {
  return <RequireAuth roles={navItem("messages").roles}>{(user) => <ContactMessages me={user} />}</RequireAuth>;
}

function ContactMessages({ me }: { me: User }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.contactMessages;
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState<Filter>("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  const [open, setOpen] = useState<AdminContactMessage | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const digits = (n: number) => localizeDigits(String(n), locale);

  // Search: wait until typing stops.
  useEffect(() => {
    const id = setTimeout(() => {
      setQuery(search.trim());
      setPage(1);
    }, 400);
    return () => clearTimeout(id);
  }, [search]);

  const key = `${filter}|${query}|${page}|${reloadKey}`;
  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (filter) params.set("status", filter);
    if (query) params.set("search", query);
    void apiFetch<ListPage>(`/admin/contact-messages?${params}`).then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
      if (res.ok) setUnreadContactMessages(res.data.meta.unread_count);
    });
    return () => {
      cancelled = true;
    };
  }, [filter, query, page, key]);

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;

  /** Puts a changed message + the new unread count into the loaded list and the menu badge (no reload). */
  const replace = (m: AdminContactMessage, unread: number) => {
    setLoad((l) =>
      l?.ok
        ? { ...l, page: { ...l.page, data: l.page.data.map((x) => (x.id === m.id ? m : x)), meta: { ...l.page.meta, unread_count: unread } } }
        : l,
    );
    setUnreadContactMessages(unread);
  };

  const openMessage = (m: AdminContactMessage) => {
    setFlash(null);
    setOpen(m);
    if (m.is_read) return;
    // Opening marks it read.
    void apiFetch<{ data: AdminContactMessage; meta: { unread_count: number } }>(`/admin/contact-messages/${m.id}/read`, { method: "POST" }).then(
      (res) => {
        if (!res.ok) return;
        replace(res.data.data, res.data.meta.unread_count);
        setOpen((o) => (o?.id === m.id ? res.data.data : o));
      },
    );
  };

  return (
    <div className="space-y-5">
      <p className="max-w-3xl">{c.subtitle}</p>
      {flash && <Alert tone="success">{flash}</Alert>}

      <Card className="p-4 sm:p-5">
        <form role="search" onSubmit={(e) => e.preventDefault()} className="grid gap-4 md:grid-cols-[2fr_1fr]">
          <Input
            label={c.search}
            type="search"
            placeholder={c.searchPlaceholder}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            maxLength={100}
          />
          <Select
            label={c.show}
            value={filter}
            onChange={(e) => {
              setFilter(e.target.value as Filter);
              setPage(1);
            }}
          >
            <option value="">{c.all}</option>
            <option value="unread">{c.unread}</option>
            <option value="read">{c.read}</option>
          </Select>
        </form>
      </Card>

      {load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{c.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {c.retry}
          </Button>
        </Card>
      ) : !list ? (
        <ul className="space-y-3" aria-busy="true">
          <li className="sr-only" role="status">
            {c.loading}
          </li>
          {[0, 1, 2].map((i) => (
            <li key={i} className="h-28 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : list.data.length === 0 ? (
        <Card className="text-center text-muted">{filter || query ? c.emptyFiltered : c.empty}</Card>
      ) : (
        <div className={stale ? "opacity-60 transition-opacity" : undefined}>
          <p className="mb-3 text-sm text-muted">
            {fill(c.total, { n: digits(list.meta.total) })}
            {list.meta.unread_count > 0 && ` · ${fill(c.unreadTotal, { n: digits(list.meta.unread_count) })}`}
          </p>
          <ul className="space-y-3">
            {list.data.map((m) => (
              <li key={m.id}>
                <Card className={`p-4 sm:p-5 ${m.is_read ? "" : "border-l-4 border-l-primary bg-primary-soft/40"}`}>
                  <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div className="min-w-0">
                      <p className={`break-words text-heading ${m.is_read ? "font-medium" : "font-bold"}`}>
                        {!m.is_read && <span className="sr-only">{c.unread}: </span>}
                        {m.subject}
                      </p>
                      <p className="text-sm text-muted">
                        {m.name} · {localizeDigits(m.phone, locale)}
                      </p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      {!m.is_read && (
                        <span className="rounded-full bg-primary px-2.5 py-0.5 text-xs font-semibold text-primary-foreground">{c.newBadge}</span>
                      )}
                      <span className="text-xs text-muted">{formatWhen(m.created_at, locale)}</span>
                    </div>
                  </div>
                  <p className="mt-2 line-clamp-2 text-sm wrap-anywhere text-foreground">{m.message}</p>
                  <div className="mt-3 flex justify-end">
                    <Button variant="outline" size="sm" onClick={() => openMessage(m)}>
                      {c.open}
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
                {c.prev}
              </Button>
              <span className="text-sm text-muted">{fill(c.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {c.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}

      {open && (
        <MessageDetail
          message={open}
          canDelete={me.role === "super_admin"}
          onClose={() => setOpen(null)}
          onUnread={(m, unread) => {
            replace(m, unread);
            setOpen(null);
          }}
          onDeleted={(text, unread) => {
            setOpen(null);
            setFlash(text);
            setUnreadContactMessages(unread);
            setReloadKey((k) => k + 1);
          }}
        />
      )}
    </div>
  );
}

/** One message in full: sender, mobile, email, date, text; Mark unread; Delete (Super Admin, with a confirm step). */
function MessageDetail({
  message: m,
  canDelete,
  onClose,
  onUnread,
  onDeleted,
}: {
  message: AdminContactMessage;
  canDelete: boolean;
  onClose: () => void;
  onUnread: (m: AdminContactMessage, unread: number) => void;
  onDeleted: (text: string, unread: number) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.contactMessages;
  const [busy, setBusy] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const markUnread = async () => {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ data: AdminContactMessage; meta: { unread_count: number } }>(`/admin/contact-messages/${m.id}/unread`, { method: "POST" });
    setBusy(false);
    if (!res.ok) return setError(failureText(res, t));
    onUnread(res.data.data, res.data.meta.unread_count);
  };

  const remove = async () => {
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ message: string; meta: { unread_count: number } }>(`/admin/contact-messages/${m.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) return setError(failureText(res, t));
    onDeleted(res.data.message, res.data.meta.unread_count);
  };

  return (
    <Modal title={c.message} onClose={busy ? () => {} : onClose}>
      <div className="space-y-4">
        <div>
          <p className="text-xs text-muted">{c.subject}</p>
          <p className="font-semibold break-words text-heading">{m.subject}</p>
        </div>
        <dl className="grid gap-3 rounded-control border border-border px-3 py-2.5 text-sm sm:grid-cols-2">
          <div className="min-w-0">
            <dt className="text-xs text-muted">{c.from}</dt>
            <dd className="font-medium break-words text-heading">{m.name}</dd>
          </div>
          <div>
            <dt className="text-xs text-muted">{c.mobile}</dt>
            <dd>
              <a href={`tel:${m.phone}`} className="inline-flex items-center gap-1 font-medium text-primary-text hover:underline">
                <Icon name="phone" className="size-4" />
                {localizeDigits(m.phone, locale)}
              </a>
            </dd>
          </div>
          <div className="min-w-0">
            <dt className="text-xs text-muted">{c.email}</dt>
            <dd className="wrap-anywhere">
              {m.email ? (
                <a href={`mailto:${m.email}`} className="font-medium text-primary-text hover:underline">
                  {m.email}
                </a>
              ) : (
                <span className="text-muted italic">{c.noEmail}</span>
              )}
            </dd>
          </div>
          <div>
            <dt className="text-xs text-muted">{c.received}</dt>
            <dd className="font-medium text-heading">{formatWhen(m.created_at, locale)}</dd>
          </div>
        </dl>
        <div className="max-h-72 overflow-y-auto rounded-control bg-background px-3 py-2.5 wrap-anywhere whitespace-pre-line text-foreground">
          {m.message}
        </div>

        {error && <Alert tone="danger">{error}</Alert>}

        {confirming ? (
          <div className="space-y-3 rounded-control border border-danger/30 bg-danger-soft/50 p-3">
            <p className="text-sm font-medium text-danger">{c.deleteConfirm}</p>
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="ghost" onClick={() => setConfirming(false)} disabled={busy}>
                {c.cancel}
              </Button>
              <Button variant="danger" onClick={() => void remove()} disabled={busy}>
                <Icon name="trash" className="size-4" />
                {busy ? c.working : c.deleteYes}
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap justify-end gap-2 pt-1">
            {canDelete && (
              <Button variant="danger" className="sm:mr-auto" onClick={() => setConfirming(true)} disabled={busy}>
                <Icon name="trash" className="size-4" />
                {c.delete}
              </Button>
            )}
            <Button variant="ghost" onClick={onClose} disabled={busy}>
              {c.close}
            </Button>
            {m.is_read && (
              <Button variant="outline" onClick={() => void markUnread()} disabled={busy}>
                <Icon name="mail" className="size-4" />
                {busy ? c.working : c.markUnread}
              </Button>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}
