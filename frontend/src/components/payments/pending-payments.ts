"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch, type Paginated } from "@/lib/api";

// Number of payments waiting for an admin's check (sidebar badge, Phase 7c).
// One shared value, so the Payment Review page can refresh the badge after approve / reject.
let count: number | null = null;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Reloads the waiting count (admin + super_admin only — others get 403 and the value is cleared). */
export async function refreshPendingPayments(): Promise<void> {
  const res = await apiFetch<Paginated<unknown>>("/admin/contributions?status=pending");
  count = res.ok ? res.data.meta.total : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

/** The last loaded waiting count, without loading it again. */
export function usePendingPaymentsCount(): number | null {
  return useSyncExternalStore(subscribe, () => count, () => null);
}

/** The waiting count; loads it when `enabled` and again whenever `refreshKey` changes (e.g. the page path). */
export function usePendingPayments(enabled: boolean, refreshKey: string): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refreshPendingPayments();
  }, [enabled, refreshKey]);
  return enabled ? value : null;
}
