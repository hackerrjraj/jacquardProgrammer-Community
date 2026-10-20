"use client";

import { useEffect, useSyncExternalStore } from "react";
import { apiFetch } from "@/lib/api";
import { onChatLive, startChatLive, stopChatLive } from "./chat-live";

// Unread chat messages over all conversations (sidebar badge, Phase 9b). One shared value, so the chat
// screens can update the badge right after they load a list or mark a conversation as read.
let count: number | null = null;
// Each new value bumps this, so an older request that answers late cannot overwrite a newer value (live updates, 9c).
let version = 0;
const listeners = new Set<() => void>();

function subscribe(onChange: () => void) {
  listeners.add(onChange);
  return () => listeners.delete(onChange);
}

/** Sets the badge from a value the chat API already returned (no extra request). */
export function setChatUnread(value: number): void {
  version++;
  count = value;
  listeners.forEach((l) => l());
}

/** Reloads the unread total (approved accounts; others get 403 and the value is cleared). */
export async function refreshChatUnread(): Promise<void> {
  const started = ++version;
  const res = await apiFetch<{ data: { unread_total: number } }>("/chat/unread");
  if (started !== version) return;
  count = res.ok ? res.data.data.unread_total : res.status === 0 ? count : null;
  listeners.forEach((l) => l());
}

/**
 * The unread total; loads it when `enabled` and again whenever `refreshKey` changes (e.g. the page path).
 * Also keeps the live chat connection for `userId` open (9c) and reloads the total on every live chat event.
 */
export function useChatUnread(enabled: boolean, refreshKey: string, userId?: number): number | null {
  const value = useSyncExternalStore(subscribe, () => count, () => null);
  useEffect(() => {
    if (enabled) void refreshChatUnread();
  }, [enabled, refreshKey]);
  useEffect(() => {
    if (!enabled || !userId) return;
    startChatLive(userId);
    let timer: ReturnType<typeof setTimeout> | undefined;
    const off = onChatLive(() => {
      // Several events often come together (new message + read) → one request.
      clearTimeout(timer);
      timer = setTimeout(() => void refreshChatUnread(), 300);
    });
    return () => {
      off();
      clearTimeout(timer);
      stopChatLive(); // logout, other account, or leaving the dashboard
    };
  }, [enabled, userId]);
  return enabled ? value : null;
}
