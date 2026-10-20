"use client";

import { useDictionary } from "@/i18n/client";
import { Icon } from "@/components/ui/icon";
import { dismissInstall, promptInstall, useInstallState } from "./install-store";

/**
 * Top-bar "Install app" button (Android / desktop Chrome / Edge). Tablets and up only —
 * on phones it lives in the account menu so the page title keeps its room.
 */
export function InstallAppButton() {
  const p = useDictionary().pwa;
  const { canInstall } = useInstallState();
  if (!canInstall) return null;
  return (
    <button
      type="button"
      onClick={() => void promptInstall()}
      aria-label={p.install}
      title={p.installTitle}
      className="hidden h-9 shrink-0 items-center gap-1.5 rounded-full border border-border px-2 text-sm font-medium text-foreground hover:border-primary hover:text-primary-text sm:flex md:px-3"
    >
      <Icon name="download" className="size-4.5" />
      <span className="hidden md:inline">{p.installShort}</span>
    </button>
  );
}

/** Account-menu item, phones only (the top-bar button is hidden there). */
export function InstallAppMenuItem({ className, onDone }: { className: string; onDone: () => void }) {
  const p = useDictionary().pwa;
  const { canInstall } = useInstallState();
  if (!canInstall) return null;
  return (
    <button
      role="menuitem"
      type="button"
      onClick={() => {
        onDone();
        void promptInstall();
      }}
      className={`${className} sm:hidden`}
    >
      <Icon name="download" className="size-4" />
      {p.install}
    </button>
  );
}

/** iPhone / iPad Safari: short "Share → Add to Home Screen" hint with a close button. */
export function IosInstallHint() {
  const p = useDictionary().pwa;
  const { iosHint } = useInstallState();
  if (!iosHint) return null;
  return (
    <div className="mb-4 flex items-start gap-3 rounded-card border border-primary/30 bg-primary-soft p-3 text-sm sm:mb-6">
      <Icon name="download" className="mt-0.5 size-5 shrink-0 text-primary-text" />
      <p className="min-w-0 flex-1 text-foreground">
        <span className="font-semibold">{p.iosTitle}</span>
        <span className="block text-text">{p.iosHint}</span>
      </p>
      <button
        type="button"
        onClick={dismissInstall}
        aria-label={p.close}
        title={p.close}
        className="-m-1 flex size-8 shrink-0 items-center justify-center rounded-full text-muted hover:bg-white hover:text-foreground"
      >
        <Icon name="close" className="size-4" />
      </button>
    </div>
  );
}
