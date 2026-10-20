"use client";

import { useSyncExternalStore } from "react";

/**
 * "Install app" state (Phase 10b, MASTER_SPEC §36), shared by the top-bar button,
 * the account-menu item and the iPhone / iPad hint.
 * - Android / desktop Chrome / Edge: the browser's `beforeinstallprompt` event is kept so our
 *   button can open the install dialog.
 * - iPhone / iPad Safari: no such event — a short "Share → Add to Home Screen" hint instead.
 * - Hidden when the app already runs installed, and for 14 days after the member says no.
 */

/** Chrome's install event (not in TypeScript's DOM types). */
interface BeforeInstallPromptEvent extends Event {
  prompt(): Promise<void>;
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>;
}

export type InstallState = {
  /** The browser can show its install dialog (our "Install app" button). */
  canInstall: boolean;
  /** iPhone / iPad Safari: show the "Add to Home Screen" hint. */
  iosHint: boolean;
};

const DISMISS_KEY = "jpphaf.installDismissedAt";
const DISMISS_DAYS = 14;
const HIDDEN: InstallState = { canInstall: false, iosHint: false };

let deferred: BeforeInstallPromptEvent | null = null;
let installed = false;
let dismissed = false;
let ios = false;
let state: InstallState = HIDDEN;
let started = false;
const listeners = new Set<() => void>();

function readDismissed(): boolean {
  try {
    const at = Number(window.localStorage.getItem(DISMISS_KEY));
    return at > 0 && Date.now() - at < DISMISS_DAYS * 24 * 60 * 60 * 1000;
  } catch {
    return false;
  }
}

function isStandalone(): boolean {
  return (
    window.matchMedia("(display-mode: standalone)").matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  );
}

/** Safari on iPhone / iPad (iPadOS reports itself as a Mac with touch). Not Chrome / Firefox / Edge for iOS. */
function isIosSafari(): boolean {
  const ua = navigator.userAgent;
  const iDevice = /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  return iDevice && /Safari/.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua);
}

function update() {
  const visible = !installed && !dismissed;
  const next = { canInstall: visible && deferred !== null, iosHint: visible && ios };
  if (next.canInstall === state.canInstall && next.iosHint === state.iosHint) return;
  state = next;
  listeners.forEach((fn) => fn());
}

/** Starts listening as soon as this file loads (the event can fire before React is ready). */
function start() {
  if (started || typeof window === "undefined") return;
  started = true;
  installed = isStandalone();
  dismissed = readDismissed();
  ios = isIosSafari();

  window.addEventListener("beforeinstallprompt", (event) => {
    event.preventDefault(); // no automatic browser banner — our own button is used
    deferred = event as BeforeInstallPromptEvent;
    update();
  });
  window.addEventListener("appinstalled", () => {
    installed = true;
    deferred = null;
    update();
  });
  window.matchMedia("(display-mode: standalone)").addEventListener("change", (e) => {
    if (e.matches) {
      installed = true;
      update();
    }
  });
  update();
}

start();

/** Hides the button / hint for 14 days. */
export function dismissInstall() {
  try {
    window.localStorage.setItem(DISMISS_KEY, String(Date.now()));
  } catch {
    // Storage blocked (private mode) — hidden for this visit only.
  }
  dismissed = true;
  update();
}

/** Opens the browser's install dialog. "Not now" there counts as dismissed (14 days). */
export async function promptInstall() {
  const event = deferred;
  if (!event) return;
  deferred = null; // the browser allows each event to be used only once
  update();
  try {
    await event.prompt();
    const { outcome } = await event.userChoice;
    if (outcome === "accepted") installed = true;
    else dismissInstall();
  } catch {
    // Dialog could not open — the browser offers the event again on a later visit.
  }
  update();
}

function subscribe(onChange: () => void) {
  start();
  listeners.add(onChange);
  return () => {
    listeners.delete(onChange);
  };
}

export function useInstallState(): InstallState {
  return useSyncExternalStore(subscribe, () => state, () => HIDDEN);
}
