"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch } from "@/lib/api";

// Number of open chat reports (sidebar badge, Phase 9d-1) — same pattern as the waiting-payments badge.
// One shared value, so the Chat Reports page can update the badge right after Reviewed / Dismiss.
let count: number | null = null;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Sets the badge from a value the API already returned (no extra request). */
export function setOpenChatReports(value: number): void {
  count = value;
  listeners.forEach((l) => l());
}

/** Reloads the open count (admin + super_admin only — others get 403 and the value is cleared). */
export async function refreshOpenChatReports(): Promise<void> {
  const res = await apiFetch<{ meta: { open_count: number } }>("/admin/chat-reports?status=open");
  count = res.ok ? res.data.meta.open_count : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

/** The open count; loads it when `enabled` and again whenever `refreshKey` changes (e.g. the page path). */
export function useOpenChatReports(enabled: boolean, refreshKey: string): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refreshOpenChatReports();
  }, [enabled, refreshKey]);
  return enabled ? value : null;
}
