"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch, notifyAdminChange } from "@/lib/api";
import { onCommitteeChatLive } from "./chat-live";

// Unread Committee Chat messages for committee members who are not admins (admins get the number inside
// GET /api/admin/badge-counts). One shared value, so the chat screen can set the badge right after it marks the chat read.
let count: number | null = null;
let version = 0;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Sets the badge from a value the API already returned (no extra request). Also tells the admin badges to reload. */
export function setCommitteeChatUnread(value: number): void {
  version++;
  count = value;
  listeners.forEach((l) => l());
  notifyAdminChange();
}

export async function refreshCommitteeChatUnread(): Promise<void> {
  const started = ++version;
  const res = await apiFetch<{ data: { unread_total: number } }>("/committee-chat/unread");
  if (started !== version) return;
  count = res.ok ? res.data.data.unread_total : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

/** The unread total; loads it when `enabled`, on every page change and on every live Committee Chat event. */
export function useCommitteeChatUnread(enabled: boolean, refreshKey: string): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refreshCommitteeChatUnread();
  }, [enabled, refreshKey]);
  useEffect(() => {
    if (!enabled) return;
    let timer: ReturnType<typeof setTimeout> | undefined;
    const off = onCommitteeChatLive(() => {
      clearTimeout(timer);
      timer = setTimeout(() => void refreshCommitteeChatUnread(), 300);
    });
    return () => {
      off();
      clearTimeout(timer);
    };
  }, [enabled]);
  return enabled ? value : null;
}
