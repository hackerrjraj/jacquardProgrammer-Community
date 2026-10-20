"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch } from "@/lib/api";

// Number of unread contact messages (sidebar badge, Phase 11a) — same pattern as the open chat reports badge.
// One shared value, so the Contact Messages page can update the badge right after read / unread / delete.
let count: number | null = null;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Sets the badge from a value the API already returned (no extra request). */
export function setUnreadContactMessages(value: number): void {
  count = value;
  listeners.forEach((l) => l());
}

/** Reloads the unread count (admin + super_admin only — others get 403 and the value is cleared). */
async function refresh(): Promise<void> {
  const res = await apiFetch<{ meta: { unread_count: number } }>("/admin/contact-messages?status=unread");
  count = res.ok ? res.data.meta.unread_count : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

/** The unread count; loads it when `enabled` and again whenever `refreshKey` changes (e.g. the page path). */
export function useUnreadContactMessages(enabled: boolean, refreshKey: string): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refresh();
  }, [enabled, refreshKey]);
  return enabled ? value : null;
}
