"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch, type AppNotification, type Paginated } from "@/lib/api";
import { onNotificationLive } from "@/components/chat/chat-live";

// Unread notifications (bell badge, Phase 10a). One shared value, so the bell and the notifications page stay in step.
let count: number | null = null;
// Each new value bumps this, so an older request that answers late cannot overwrite a newer value.
let version = 0;
const listeners = new Set<() => void>();
// Tells open lists (bell panel, page) to reload after a live "new notification".
let liveTick = 0;

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

export type NotificationPage = Paginated<AppNotification> & { meta: { unread_count: number } };

/** Sets the badge from a value the API already returned (no extra request). */
export function setNotificationUnread(value: number): void {
  version++;
  count = value;
  listeners.forEach((l) => l());
}

/** Reloads the unread count (approved accounts; others get 403 and the value is cleared). */
export async function refreshNotificationUnread(): Promise<void> {
  const started = ++version;
  const res = await apiFetch<{ data: { unread_count: number } }>("/notifications/unread");
  if (started !== version) return;
  count = res.ok ? res.data.data.unread_count : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

export async function markNotificationRead(id: number): Promise<void> {
  const res = await apiFetch<{ data: { unread_count: number } }>(`/notifications/${id}/read`, { method: "POST" });
  if (res.ok) setNotificationUnread(res.data.data.unread_count);
}

export async function markAllNotificationsRead(): Promise<boolean> {
  const res = await apiFetch<{ data: { unread_count: number } }>("/notifications/read-all", { method: "POST" });
  if (res.ok) setNotificationUnread(0);
  return res.ok;
}

/** Bumps on every live notification — use as a reload key in lists. */
export function useNotificationLiveTick(): number {
  return useSyncExternalStore(subscribe, () => liveTick, () => 0);
}

/**
 * The unread count; loads it when `enabled` and again whenever `refreshKey` changes (e.g. the page path) and on every
 * live "new notification" (the live connection itself is kept open by the chat badge, 9c).
 */
export function useNotificationUnread(enabled: boolean, refreshKey: string): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refreshNotificationUnread();
  }, [enabled, refreshKey]);
  useEffect(() => {
    if (!enabled) return;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const off = onNotificationLive(() => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        liveTick++;
        void refreshNotificationUnread();
      }, 300);
    });
    return () => {
      off();
      clearTimeout(timer);
    };
  }, [enabled]);
  return enabled ? value : null;
}
