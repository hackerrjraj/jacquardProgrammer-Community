/*
 * JPPHAF service worker (Phase 10b, MASTER_SPEC §36).
 *
 * Deliberately small and safe:
 * - Caches ONLY static files: built JS/CSS/fonts (/_next/static/…), app icons and the offline page.
 * - NEVER caches pages, API answers (the API is another address, so it is not touched at all),
 *   login, private files, chat or notifications.
 * - A page that cannot load because the phone is offline shows /offline.html instead.
 *
 * Change VERSION whenever this file changes: open tabs then show "Update available — Reload".
 * Push (Phase 10c-2): shows the notification sent by the Laravel API and opens its page on click.
 * Chat push (Phase 10c-3): skipped while that same chat is open, visible and focused in a JPPHAF window.
 */
const VERSION = "2026-10-16.3";
const CACHE = `jpphaf-static-${VERSION}`;
const OFFLINE_URL = "/offline.html";
const PRECACHE = [OFFLINE_URL, "/icons/icon-192.png", "/icons/icon-512.png"];

// Registered as /sw.js?dev=1 while `next dev` runs: dev files are not versioned, so always
// try the network first there (the cache is only a fallback) — hot reload keeps working.
const DEV = new URL(self.location.href).searchParams.get("dev") === "1";

self.addEventListener("install", (event) => {
  // No skipWaiting() here: a new version waits until the member presses "Reload".
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)));
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys.filter((key) => key.startsWith("jpphaf-") && key !== CACHE).map((key) => caches.delete(key)),
      );
      await self.clients.claim();
    })(),
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "SKIP_WAITING") self.skipWaiting();
});

/** Static files that are safe to keep: they are the same for everyone and contain no data. */
function isStaticAsset(url) {
  return url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/");
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);
  // Other addresses (the Laravel API, Reverb, uploaded files) are never touched.
  if (url.origin !== self.location.origin) return;

  // Pages: always from the network, never stored. Offline → the offline page.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request).catch(async () => (await caches.match(OFFLINE_URL)) || Response.error()),
    );
    return;
  }

  if (isStaticAsset(url)) {
    event.respondWith(DEV ? networkFirst(request) : cacheFirst(request));
  }
  // Everything else (page data, images, manifest, …) goes to the network as usual.
});

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  const response = await fetch(request);
  await store(request, response);
  return response;
}

async function networkFirst(request) {
  try {
    const response = await fetch(request);
    await store(request, response);
    return response;
  } catch (error) {
    const cached = await caches.match(request);
    if (cached) return cached;
    throw error;
  }
}

async function store(request, response) {
  if (!response || response.status !== 200 || response.type !== "basic") return;
  const cacheControl = response.headers.get("Cache-Control") || "";
  if (/no-store|private/i.test(cacheControl)) return;
  const cache = await caches.open(CACHE);
  await cache.put(request, response.clone());
}

// ---- Push notifications (Phase 10c-2) ----

const PUSH_FALLBACK_URL = "/dashboard/notifications";
const PUSH_ICON = "/icons/icon-192.png";

/** Only pages of this website: a relative path like "/dashboard/payments" (no other addresses). */
function safePushUrl(value) {
  if (typeof value !== "string" || !value.startsWith("/") || value.startsWith("//") || value.includes("\\")) {
    return PUSH_FALLBACK_URL;
  }
  try {
    const url = new URL(value, self.location.origin);
    return url.origin === self.location.origin ? url.pathname + url.search + url.hash : PUSH_FALLBACK_URL;
  } catch {
    return PUSH_FALLBACK_URL;
  }
}

self.addEventListener("push", (event) => {
  let payload = {};
  try {
    payload = event.data ? event.data.json() : {};
  } catch {
    payload = { body: event.data ? event.data.text() : "" };
  }
  const data = payload.data && typeof payload.data === "object" ? payload.data : {};
  const options = {
    body: typeof payload.body === "string" ? payload.body : "",
    icon: typeof payload.icon === "string" && payload.icon.startsWith("/") ? payload.icon : PUSH_ICON,
    badge: PUSH_ICON,
    lang: typeof payload.lang === "string" ? payload.lang : undefined,
    data: { url: safePushUrl(data.url) },
  };
  if (typeof payload.tag === "string" && payload.tag) {
    options.tag = payload.tag;
    // Same tag = replaces the earlier notification (e.g. one per chat); renotify still alerts for the new one.
    options.renotify = payload.renotify === true;
  }
  event.waitUntil(
    (async () => {
      if (await isPageOpenAndFocused(options.data.url)) return; // the member is already reading it
      await self.registration.showNotification(payload.title || "JPPHAF", options);
    })(),
  );
});

/** True when a visible, focused JPPHAF window is on exactly this page (e.g. the chat the message belongs to). */
async function isPageOpenAndFocused(path) {
  if (path === PUSH_FALLBACK_URL) return false;
  try {
    const tabs = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
    return tabs.some((client) => {
      const url = new URL(client.url);
      return (
        url.origin === self.location.origin &&
        url.pathname.replace(/\/+$/, "") === path.replace(/\/+$/, "") &&
        client.visibilityState === "visible" &&
        client.focused
      );
    });
  } catch {
    return false;
  }
}

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const target = safePushUrl(event.notification.data && event.notification.data.url);
  event.waitUntil(
    (async () => {
      const tabs = await self.clients.matchAll({ type: "window", includeUncontrolled: true });
      const tab = tabs.find((client) => new URL(client.url).origin === self.location.origin);
      if (tab) {
        await tab.focus();
        try {
          await tab.navigate(target);
          return;
        } catch {
          // Tab not controlled by this worker (e.g. opened before it) — open a new window instead.
        }
      }
      await self.clients.openWindow(target);
    })(),
  );
});
