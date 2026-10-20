"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";
import { useSyncExternalStore } from "react";
import { apiFetch, type CommitteeChatMessage } from "@/lib/api";

// Live chat delivery over Reverb (Phase 9c). Extra on top of the 9b API screens: when the chat server is down or
// reconnecting, everything still works through the API and the screens show a quiet "live updates off" hint.

export type ChatLiveMessage = {
  conversation_id: number;
  message: { id: number; sender_id: number; body: string; created_at: string | null };
};
export type ChatLiveRead = { conversation_id: number; reader_id: number; last_read_id: number };
/** "resync" = the connection came back after a gap — reload what may have been missed. */
export type ChatLiveEvent = { type: "message"; data: ChatLiveMessage } | { type: "read"; data: ChatLiveRead } | { type: "resync" };

/** Event names sent by the backend (broadcastAs, with the leading dot = no namespace). */
export const MESSAGE_EVENT = ".chat.message";
export const READ_EVENT = ".chat.read";
/** Private Committee Chat (own channel, same connection). */
export const COMMITTEE_MESSAGE_EVENT = ".committee_chat.message";
export const COMMITTEE_READ_EVENT = ".committee_chat.read";
/** New in-app notification (Phase 10a) on the same own channel — the bell reloads its count. */
export const NOTIFICATION_EVENT = ".notification.new";

const KEY = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
const HOST = process.env.NEXT_PUBLIC_REVERB_HOST ?? "localhost";
const PORT = Number(process.env.NEXT_PUBLIC_REVERB_PORT ?? 8080);
const TLS = process.env.NEXT_PUBLIC_REVERB_SCHEME === "https";
/** No hint during a normal first connect; only when it takes longer than this. */
const GRACE_MS = 4000;

let echo: Echo<"reverb"> | null = null;
let userChannel: string | null = null;

// ---- connection status ("on" / "off"; "connecting" shows nothing) ----

type LiveStatus = "connecting" | "on" | "off";
let status: LiveStatus = KEY ? "connecting" : "off";
let everOff = false;
let graceTimer: ReturnType<typeof setTimeout> | undefined;
const statusListeners = new Set<() => void>();

function setStatus(next: LiveStatus) {
  if (next === status) return;
  const cameBack = next === "on" && everOff;
  if (next === "off") everOff = true;
  status = next;
  statusListeners.forEach((l) => l());
  if (cameBack) {
    everOff = false;
    emit({ type: "resync" });
    notificationListeners.forEach((l) => l());
    emitCommittee({ type: "resync" });
  }
}

function subscribeStatus(onChange: () => void) {
  statusListeners.add(onChange);
  return () => statusListeners.delete(onChange);
}

/** true while live updates are not working (shown as a small hint). */
export function useChatLiveOff(): boolean {
  return useSyncExternalStore(subscribeStatus, () => status === "off", () => false);
}

// ---- events for the chat screens ----

const eventListeners = new Set<(e: ChatLiveEvent) => void>();

function emit(e: ChatLiveEvent) {
  eventListeners.forEach((l) => l(e));
}

/** Member-wide chat events (from the member's own channel) + "resync". Returns the unsubscribe function. */
export function onChatLive(listener: (e: ChatLiveEvent) => void): () => void {
  eventListeners.add(listener);
  return () => {
    eventListeners.delete(listener);
  };
}

// ---- Committee Chat events (own channel; only members of the chat ever receive them) ----

export type CommitteeChatLiveEvent = { type: "message"; message: CommitteeChatMessage } | { type: "read" } | { type: "resync" };
const committeeListeners = new Set<(e: CommitteeChatLiveEvent) => void>();

function emitCommittee(e: CommitteeChatLiveEvent) {
  committeeListeners.forEach((l) => l(e));
}

/** Committee Chat events + "resync" after a connection gap. Returns the unsubscribe function. */
export function onCommitteeChatLive(listener: (e: CommitteeChatLiveEvent) => void): () => void {
  committeeListeners.add(listener);
  return () => {
    committeeListeners.delete(listener);
  };
}

// ---- notifications (Phase 10a): kept apart so the chat screens don't reload on them ----

const notificationListeners = new Set<() => void>();

/** Called on every new notification and after the connection came back. Returns the unsubscribe function. */
export function onNotificationLive(listener: () => void): () => void {
  notificationListeners.add(listener);
  return () => {
    notificationListeners.delete(listener);
  };
}

// ---- the connection ----

/** The shared Echo connection (created on first use in the browser; null when not configured). */
export function chatEcho(): Echo<"reverb"> | null {
  if (echo || !KEY || typeof window === "undefined") return echo;
  const instance = new Echo<"reverb">({
    broadcaster: "reverb",
    Pusher,
    key: KEY,
    wsHost: HOST,
    wsPort: PORT,
    wssPort: PORT,
    forceTLS: TLS,
    enabledTransports: ["ws", "wss"],
    // Channel login with the website's normal session (cookie + CSRF), same as every other API call.
    channelAuthorization: {
      endpoint: "",
      transport: "ajax",
      customHandler: ({ socketId, channelName }, callback) => {
        void apiFetch<{ auth: string; channel_data?: string }>("/broadcasting/auth", {
          method: "POST",
          body: { socket_id: socketId, channel_name: channelName },
        }).then((res) => (res.ok ? callback(null, res.data) : callback(new Error(`auth ${res.status}`), null)));
      },
    },
  });
  echo = instance;
  status = "connecting";
  statusListeners.forEach((l) => l());
  clearTimeout(graceTimer);
  graceTimer = setTimeout(() => {
    if (status === "connecting") setStatus("off");
  }, GRACE_MS);
  instance.connector.pusher.connection.bind("state_change", ({ current }: { current: string }) => {
    if (echo !== instance) return; // an old, closed connection (logout / page switch) must not change the hint
    if (current === "connected") setStatus("on");
    else if (current !== "connecting" && current !== "initialized") setStatus("off");
    // "connecting" while reconnecting keeps the current hint.
  });
  return instance;
}

/** Listens on the member's own channel (menu badge, chat list, notification bell). Call with null on logout. */
export function startChatLive(userId: number | null): void {
  const name = userId ? `App.Models.User.${userId}` : null;
  if (name === userChannel) return;
  if (!name) return stopChatLive();
  const e = chatEcho();
  if (!e) return;
  if (userChannel) e.leave(userChannel);
  userChannel = name;
  e.private(name)
    .listen(MESSAGE_EVENT, (data: ChatLiveMessage) => emit({ type: "message", data }))
    .listen(READ_EVENT, (data: ChatLiveRead) => emit({ type: "read", data }))
    .listen(COMMITTEE_MESSAGE_EVENT, (data: { message: CommitteeChatMessage }) => emitCommittee({ type: "message", message: data.message }))
    .listen(COMMITTEE_READ_EVENT, () => emitCommittee({ type: "read" }))
    .listen(NOTIFICATION_EVENT, () => notificationListeners.forEach((l) => l()));
}

/** Closes the connection (logout / account not approved). */
export function stopChatLive(): void {
  userChannel = null;
  clearTimeout(graceTimer);
  echo?.disconnect();
  echo = null;
  everOff = false;
  status = KEY ? "connecting" : "off";
  statusListeners.forEach((l) => l());
}
