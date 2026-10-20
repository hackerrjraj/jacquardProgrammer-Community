"use client";

import { useEffect, useRef, useState } from "react";
import { useDictionary } from "@/i18n/client";
import { Icon } from "@/components/ui/icon";
// Loaded on every page so the browser's install event is caught early (see install-store).
import "./install-store";

/**
 * Service worker (Phase 10b): registered in production builds, or in `next dev` only when
 * NEXT_PUBLIC_ENABLE_SW=true (so hot reload is not disturbed). Shows a small
 * "Update available — Reload" toast when a new version of public/sw.js is waiting.
 */
const SW_ENABLED = process.env.NODE_ENV === "production" || process.env.NEXT_PUBLIC_ENABLE_SW === "true";
const SW_URL = process.env.NODE_ENV === "production" ? "/sw.js" : "/sw.js?dev=1";
const UPDATE_CHECK_MS = 60 * 60 * 1000;

function isOurWorker(registration: ServiceWorkerRegistration) {
  const worker = registration.active ?? registration.waiting ?? registration.installing;
  return !!worker && new URL(worker.scriptURL).pathname === "/sw.js";
}

export function PwaSetup() {
  const t = useDictionary().pwa;
  const [waiting, setWaiting] = useState<ServiceWorker | null>(null);
  const [hidden, setHidden] = useState(false);
  const reloading = useRef(false);

  useEffect(() => {
    if (!("serviceWorker" in navigator)) return;
    const sw = navigator.serviceWorker;

    if (!SW_ENABLED) {
      // Dev without the switch: remove a worker left over from an earlier test, so it can't serve old files.
      void sw.getRegistrations().then((list) => list.filter(isOurWorker).forEach((r) => void r.unregister()));
      return;
    }

    let registration: ServiceWorkerRegistration | null = null;
    let cancelled = false;
    const offer = (worker: ServiceWorker) => {
      if (!cancelled && sw.controller) setWaiting(worker); // first install: nothing to replace, no toast
    };
    const onControllerChange = () => {
      if (reloading.current) window.location.reload();
    };
    const check = () => {
      if (document.visibilityState === "visible") void registration?.update().catch(() => {});
    };

    sw.addEventListener("controllerchange", onControllerChange);
    document.addEventListener("visibilitychange", check);
    const timer = window.setInterval(check, UPDATE_CHECK_MS);

    sw.register(SW_URL, { scope: "/", updateViaCache: "none" })
      .then((reg) => {
        registration = reg;
        if (reg.waiting) offer(reg.waiting);
        reg.addEventListener("updatefound", () => {
          const next = reg.installing;
          next?.addEventListener("statechange", () => {
            if (next.state === "installed") offer(next);
          });
        });
      })
      .catch(() => {
        // Not supported here (e.g. private window) — the site works normally without it.
      });

    return () => {
      cancelled = true;
      sw.removeEventListener("controllerchange", onControllerChange);
      document.removeEventListener("visibilitychange", check);
      window.clearInterval(timer);
    };
  }, []);

  if (!waiting || hidden) return null;

  const reload = () => {
    reloading.current = true;
    waiting.postMessage({ type: "SKIP_WAITING" });
    // If the new version does not take over quickly, reload anyway.
    window.setTimeout(() => window.location.reload(), 3000);
  };

  return (
    <div
      role="status"
      className="fixed bottom-4 left-1/2 z-[70] flex w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 items-center gap-3 rounded-card bg-navy py-2.5 pr-2 pl-4 text-sm text-white shadow-panel"
    >
      <span className="min-w-0">{t.updateAvailable}</span>
      <button
        type="button"
        onClick={reload}
        className="shrink-0 rounded-full bg-brand px-3.5 py-1.5 text-sm font-semibold text-white hover:opacity-90"
      >
        {t.reload}
      </button>
      <button
        type="button"
        onClick={() => setHidden(true)}
        aria-label={t.close}
        title={t.close}
        className="flex size-8 shrink-0 items-center justify-center rounded-full text-white/70 hover:bg-white/10 hover:text-white"
      >
        <Icon name="close" className="size-4" />
      </button>
    </div>
  );
}
