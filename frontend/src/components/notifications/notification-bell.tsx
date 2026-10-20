"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { chatTime } from "@/components/chat/chat-list";
import { Icon, type IconName } from "@/components/ui/icon";
import { apiFetch, type AppNotification, type NotificationType } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import {
  markAllNotificationsRead,
  markNotificationRead,
  setNotificationUnread,
  useNotificationLiveTick,
  type NotificationPage,
} from "./notifications-shared";

const ICONS: Record<NotificationType, IconName> = {
  member_approved: "userCheck",
  payment_approved: "wallet",
  payment_rejected: "wallet",
  aid_approved: "heart",
  aid_rejected: "heart",
  aid_paid: "banknote",
  aid_vote_open: "vote",
  notice_published: "megaphone",
  payment_due_reminder: "wallet",
};

const NEGATIVE: NotificationType[] = ["payment_rejected", "aid_rejected"];

/** One notification: icon, title, text, time; bold + dot while unread. Opening it marks it as read. */
export function NotificationRow({ item, onOpen }: { item: AppNotification; onOpen?: () => void }) {
  const t = useDictionary();
  const locale = useLocale();
  const negative = NEGATIVE.includes(item.type);
  return (
    <Link
      href={item.link}
      onClick={() => {
        if (!item.read) void markNotificationRead(item.id);
        onOpen?.();
      }}
      className={`flex gap-3 px-4 py-3 transition-colors hover:bg-background ${item.read ? "" : "bg-primary-soft/40"}`}
    >
      <span
        aria-hidden="true"
        className={`mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full ${
          negative ? "bg-danger-soft text-danger" : "bg-primary-soft text-primary-text"
        }`}
      >
        <Icon name={ICONS[item.type] ?? "bell"} className="size-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-start justify-between gap-2">
          <span className={`min-w-0 text-sm wrap-anywhere text-heading ${item.read ? "font-medium" : "font-bold"}`}>{item.title}</span>
          {!item.read && (
            <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary">
              <span className="sr-only">{t.notifications.unreadMark}</span>
            </span>
          )}
        </span>
        <span className="mt-0.5 line-clamp-3 block text-sm wrap-anywhere text-muted">{item.body}</span>
        <span className="mt-1 block text-xs text-muted">{chatTime(item.created_at, locale, true)}</span>
      </span>
    </Link>
  );
}

/** Bell in the dashboard top bar (Phase 10a): unread count + a panel with the latest 20 notifications. */
export function NotificationBell({ count }: { count: number | null }) {
  const t = useDictionary();
  const n = t.notifications;
  const locale = useLocale();
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<AppNotification[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [busy, setBusy] = useState(false);
  const liveTick = useNotificationLiveTick();
  const ref = useRef<HTMLDivElement>(null);
  const countText = count ? localizeDigits(count > 99 ? "99+" : String(count), locale) : null;
  const label = countText ? `${n.title} (${fill(n.unreadCount, { n: countText })})` : n.title;

  // Load when opened, and again on live notifications while open.
  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    void apiFetch<NotificationPage>("/notifications").then((res) => {
      if (cancelled) return;
      setFailed(!res.ok);
      if (res.ok) {
        setItems(res.data.data);
        setNotificationUnread(res.data.meta.unread_count);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [open, liveTick, locale]); // texts come from the API in the current language

  // Close on outside click or Esc.
  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const readAll = async () => {
    setBusy(true);
    if (await markAllNotificationsRead()) setItems((list) => list?.map((i) => ({ ...i, read: true })) ?? null);
    setBusy(false);
  };

  return (
    <div ref={ref} className="sm:relative">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-label={label}
        title={label}
        className="relative flex size-9 shrink-0 items-center justify-center rounded-full border border-border text-foreground hover:border-primary hover:text-primary-text"
      >
        <Icon name="bell" className="size-5" />
        {countText && (
          <span aria-hidden="true" className="absolute -top-1 -right-1 min-w-5 rounded-full bg-danger px-1 text-center text-[0.65rem] leading-5 font-bold text-white">
            {countText}
          </span>
        )}
      </button>

      {open && (
        <div
          role="dialog"
          aria-label={n.title}
          className="fixed inset-x-2 top-16 z-30 flex max-h-[calc(100dvh-5rem)] flex-col rounded-card border border-border bg-surface shadow-panel sm:absolute sm:inset-x-auto sm:top-auto sm:right-0 sm:mt-2 sm:max-h-[32rem] sm:w-96"
        >
          <div className="flex items-center justify-between gap-2 border-b border-border px-4 py-3">
            <p className="font-semibold text-heading">{n.title}</p>
            {!!count && (
              <button type="button" disabled={busy} onClick={() => void readAll()} className="text-sm font-medium text-primary-text hover:underline disabled:opacity-60">
                {n.markAllRead}
              </button>
            )}
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto">
            {failed && !items ? (
              <p className="px-4 py-8 text-center text-sm text-danger">{n.loadFailed}</p>
            ) : !items ? (
              <p className="px-4 py-8 text-center text-sm text-muted" role="status">
                {n.loading}
              </p>
            ) : items.length === 0 ? (
              <div className="px-4 py-10 text-center">
                <Icon name="bell" className="mx-auto size-8 text-muted" />
                <p className="mt-2 text-sm text-muted">{n.empty}</p>
              </div>
            ) : (
              <ul>
                {items.map((item) => (
                  <li key={item.id} className="border-b border-border last:border-b-0">
                    <NotificationRow item={item} onOpen={() => setOpen(false)} />
                  </li>
                ))}
              </ul>
            )}
          </div>
          <Link
            href="/dashboard/notifications"
            onClick={() => setOpen(false)}
            className="block border-t border-border px-4 py-3 text-center text-sm font-medium text-primary-text hover:bg-background"
          >
            {n.seeAll}
          </Link>
        </div>
      )}
    </div>
  );
}
