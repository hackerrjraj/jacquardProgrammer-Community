"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch, onAdminChange } from "@/lib/api";
import { onCommitteeChatLive } from "@/components/chat/chat-live";

// Red sidebar badges for the admin queues (one request: GET /api/admin/badge-counts). admin + super_admin only.
// Reloads on page change, after any admin change (approve / reject / mark read …), and every 60 s while the tab is visible.
export type AdminBadgeCounts = {
  pending_members: number;
  pending_payments: number;
  pending_aid: number;
  open_chat_reports: number;
  unread_contact_messages: number;
  /** Private Committee Chat (admins read it here; committee members use committee-chat-unread.ts). */
  committee_chat_unread: number;
};

const POLL_MS = 60_000;
let counts: AdminBadgeCounts | null = null;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Reloads the counts (others get 403 and the value is cleared; a network error keeps the last value). */
export async function refreshAdminBadges(): Promise<void> {
  const res = await apiFetch<{ data: AdminBadgeCounts }>("/admin/badge-counts");
  counts = res.ok ? res.data.data : res.status === 0 ? counts : null;
  listeners.forEach((l) => l());
}

export function useAdminBadges(enabled: boolean, refreshKey: string): AdminBadgeCounts | null {
  const value = useSyncExternalStore(subscribe, () => counts, () => null);
  useEffect(() => {
    if (enabled) void refreshAdminBadges();
  }, [enabled, refreshKey]);
  useEffect(() => {
    if (!enabled) return;
    let debounce: ReturnType<typeof setTimeout> | undefined;
    const timer = setInterval(() => {
      if (document.visibilityState === "visible") void refreshAdminBadges();
    }, POLL_MS);
    const onVisible = () => {
      if (document.visibilityState === "visible") void refreshAdminBadges();
    };
    document.addEventListener("visibilitychange", onVisible);
    const off = onAdminChange(() => {
      clearTimeout(debounce);
      debounce = setTimeout(() => void refreshAdminBadges(), 200);
    });
    // A new / read Committee Chat message changes the Committee Chat badge.
    const offChat = onCommitteeChatLive(() => {
      clearTimeout(debounce);
      debounce = setTimeout(() => void refreshAdminBadges(), 300);
    });
    return () => {
      offChat();
      clearInterval(timer);
      clearTimeout(debounce);
      document.removeEventListener("visibilitychange", onVisible);
      off();
    };
  }, [enabled]);
  return enabled ? value : null;
}
