"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { NotificationRow } from "./notification-bell";
import { PushSwitch } from "./push-switch";
import {
  markAllNotificationsRead,
  setNotificationUnread,
  useNotificationLiveTick,
  type NotificationPage,
} from "./notifications-shared";

/** /dashboard/notifications — all my notifications, 20 per page (Phase 10a). */
export function NotificationsScreen() {
  return <RequireAuth roles={navItem("notifications").roles}>{() => <NotificationsList />}</RequireAuth>;
}

type Load = { key: number; ok: true; page: NotificationPage } | { key: number; ok: false };

function NotificationsList() {
  const t = useDictionary();
  const locale = useLocale();
  const n = t.notifications;
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  const [busy, setBusy] = useState(false);
  const liveTick = useNotificationLiveTick();
  const digits = (v: number) => localizeDigits(String(v), locale);
  const key = page * 1000 + reloadKey;

  useEffect(() => {
    let cancelled = false;
    void apiFetch<NotificationPage>(`/notifications?page=${page}`).then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
      if (res.ok) setNotificationUnread(res.data.meta.unread_count);
    });
    return () => {
      cancelled = true;
    };
  }, [page, key, liveTick, locale]); // texts come from the API in the current language

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;

  const readAll = async () => {
    setBusy(true);
    if (await markAllNotificationsRead()) setReloadKey((k) => k + 1);
    setBusy(false);
  };

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <PushSwitch />
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <p className="text-muted">{n.subtitle}</p>
        {!!list?.meta.unread_count && (
          <Button variant="ghost" size="sm" className="px-3" disabled={busy} onClick={() => void readAll()}>
            <Icon name="checkCircle" className="size-4" />
            {n.markAllRead}
          </Button>
        )}
      </div>
      {load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{n.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {n.retry}
          </Button>
        </Card>
      ) : !list ? (
        <ul className="space-y-2" aria-busy="true">
          <li className="sr-only" role="status">
            {n.loading}
          </li>
          {[0, 1, 2, 3].map((i) => (
            <li key={i} className="h-20 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : list.data.length === 0 ? (
        <Card className="p-6 text-center sm:p-8">
          <Icon name="bell" className="mx-auto size-10 text-muted" />
          <p className="mt-3 font-semibold text-heading">{n.empty}</p>
          <p className="mt-1 text-sm text-muted">{n.emptyHint}</p>
        </Card>
      ) : (
        <div className={stale ? "opacity-60 transition-opacity" : undefined}>
          <Card className="overflow-hidden p-0!">
            <ul>
              {list.data.map((item) => (
                <li key={item.id} className="border-b border-border last:border-b-0">
                  <NotificationRow item={item} />
                </li>
              ))}
            </ul>
          </Card>
          {list.meta.last_page > 1 && (
            <nav className="mt-4 flex items-center justify-between gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1 || stale} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {n.prev}
              </Button>
              <span className="text-sm text-muted">{fill(n.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {n.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}
    </div>
  );
}
