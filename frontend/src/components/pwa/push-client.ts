"use client";

import { apiFetch } from "@/lib/api";
import type { Locale } from "@/i18n/config";

/**
 * Phone / desktop push on THIS device (Phase 10c-2, MASTER_SPEC §36), on top of the 10c-1 API:
 * GET /push/public-key, POST /push/subscribe, POST /push/unsubscribe, GET / PUT /push/settings.
 * Nothing here throws: every problem comes back as a reason the switch can explain.
 */

export type PushSettings = { push_enabled: boolean; devices: number };

/** Why the switch cannot be used here (each has its own message). */
export type PushProblem = "unsupported" | "iosInstall" | "noWorker" | "blocked";

export type PushState =
  | { kind: "hidden" } // not an approved real member (API said 403) or logged out
  | { kind: "problem"; problem: PushProblem; on: false }
  | { kind: "ready"; on: boolean }
  | { kind: "error" }; // API not reachable

export type PushResult = { ok: true; on: boolean } | { ok: false; problem?: PushProblem; message?: string };

const WORKER_WAIT_MS = 3000;

function isIos(): boolean {
  const ua = navigator.userAgent;
  return /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
}

function isStandalone(): boolean {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

/** Problems that need no network: browser support, iPhone install, permission. */
function deviceProblem(): PushProblem | null {
  if (isIos() && !isStandalone()) return "iosInstall"; // iPhone / iPad: push works only from the Home Screen app
  if (!("serviceWorker" in navigator) || !("PushManager" in window) || !("Notification" in window)) return "unsupported";
  if (Notification.permission === "denied") return "blocked";
  return null;
}

/** Our active service worker, or null (e.g. `next dev` without NEXT_PUBLIC_ENABLE_SW=true). */
async function activeWorker(): Promise<ServiceWorkerRegistration | null> {
  try {
    const registration = await navigator.serviceWorker.getRegistration("/");
    if (!registration) return null;
    if (registration.active) return registration;
    // Just installed: wait a moment for it to become active.
    const ready = await Promise.race([
      navigator.serviceWorker.ready,
      new Promise<null>((resolve) => window.setTimeout(() => resolve(null), WORKER_WAIT_MS)),
    ]);
    return ready?.active ? ready : null;
  } catch {
    return null;
  }
}

async function browserSubscription(): Promise<PushSubscription | null> {
  if (typeof navigator === "undefined" || !("serviceWorker" in navigator)) return null;
  try {
    const registration = await navigator.serviceWorker.getRegistration("/");
    return (await registration?.pushManager.getSubscription()) ?? null;
  } catch {
    return null;
  }
}

/** VAPID key (base64url) → bytes for pushManager.subscribe. */
function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
  const base64 = (base64url + "=".repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, "+").replace(/_/g, "/");
  const raw = window.atob(base64);
  const bytes = new Uint8Array(new ArrayBuffer(raw.length));
  for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
  return bytes;
}

function sameKey(subscription: PushSubscription, key: Uint8Array): boolean {
  const current = subscription.options.applicationServerKey;
  if (!current) return false;
  const a = new Uint8Array(current);
  return a.length === key.length && a.every((v, i) => v === key[i]);
}

async function sendSubscription(subscription: PushSubscription, locale: Locale) {
  const json = subscription.toJSON();
  return apiFetch<{ data: PushSettings }>("/push/subscribe", {
    method: "POST",
    locale,
    body: { endpoint: json.endpoint, keys: json.keys, content_encoding: "aes128gcm" },
  });
}

/**
 * The real state of THIS device: on = the browser has a subscription AND the server has push switched on.
 * When both say on, the subscription is sent again quietly, so the server surely has this device for this
 * member (and the current language).
 */
export async function loadPushState(locale: Locale): Promise<PushState> {
  const res = await apiFetch<{ data: PushSettings }>("/push/settings", { locale });
  if (!res.ok) return res.status === 401 || res.status === 403 ? { kind: "hidden" } : { kind: "error" };

  const problem = deviceProblem();
  if (problem) return { kind: "problem", problem, on: false };
  if (!(await activeWorker())) return { kind: "problem", problem: "noWorker", on: false };

  const subscription = await browserSubscription();
  const on = !!subscription && res.data.data.push_enabled && Notification.permission === "granted";
  if (on && subscription) void sendSubscription(subscription, locale);
  return { kind: "ready", on };
}

/** Switch on: permission → public key → browser subscription → POST /push/subscribe (with the site language). */
export async function turnPushOn(locale: Locale): Promise<PushResult> {
  const problem = deviceProblem();
  if (problem) return { ok: false, problem };
  const registration = await activeWorker();
  if (!registration) return { ok: false, problem: "noWorker" };

  let permission: NotificationPermission;
  try {
    permission = await Notification.requestPermission();
  } catch {
    return { ok: false, problem: "unsupported" };
  }
  if (permission !== "granted") return { ok: false, problem: permission === "denied" ? "blocked" : undefined };

  const keyRes = await apiFetch<{ data: { public_key: string } }>("/push/public-key", { locale });
  if (!keyRes.ok || !keyRes.data.data.public_key) return { ok: false, message: keyRes.ok ? undefined : keyRes.data?.message };

  try {
    const key = keyBytes(keyRes.data.data.public_key);
    let subscription = await registration.pushManager.getSubscription();
    if (subscription && !sameKey(subscription, key)) {
      await subscription.unsubscribe(); // made with old keys — would never receive anything
      subscription = null;
    }
    subscription ??= await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    const res = await sendSubscription(subscription, locale);
    if (!res.ok) return { ok: false, message: res.data?.message };
    return { ok: true, on: res.data.data.push_enabled };
  } catch {
    return { ok: false };
  }
}

/** Switch off: remove the browser subscription + tell the server (POST /push/unsubscribe). */
export async function turnPushOff(locale: Locale): Promise<PushResult> {
  const subscription = await browserSubscription();
  if (!subscription) {
    // Nothing in this browser any more; make sure the server has nothing for it either.
    return { ok: true, on: false };
  }
  const endpoint = subscription.endpoint;
  try {
    await subscription.unsubscribe();
  } catch {
    // Keep going: the server side matters most.
  }
  const res = await apiFetch<{ data: PushSettings }>("/push/unsubscribe", { method: "POST", locale, body: { endpoint } });
  if (!res.ok) return { ok: false, message: res.data?.message };
  return { ok: true, on: false };
}

/**
 * Logout: this device stops getting the member's pushes (browser + server). Never blocks logout for long
 * and never fails it.
 */
export async function unsubscribeThisDevice(timeoutMs = 4000): Promise<void> {
  const work = (async () => {
    const subscription = await browserSubscription();
    if (!subscription) return;
    const endpoint = subscription.endpoint;
    await apiFetch("/push/unsubscribe", { method: "POST", body: { endpoint } }); // while still logged in
    await subscription.unsubscribe().catch(() => false);
  })().catch(() => {});
  await Promise.race([work, new Promise<void>((resolve) => window.setTimeout(resolve, timeoutMs))]);
}

/** Language switched: when push is on for this device, the server writes pushes in the new language. */
export async function syncPushLanguage(locale: Locale): Promise<void> {
  try {
    if (!(await browserSubscription())) return;
    const res = await apiFetch<{ data: PushSettings }>("/push/settings", { locale });
    if (!res.ok || !res.data.data.push_enabled) return;
    await apiFetch("/push/settings", { method: "PUT", locale, body: { push_enabled: true } });
  } catch {
    // Not important enough to bother the member.
  }
}
