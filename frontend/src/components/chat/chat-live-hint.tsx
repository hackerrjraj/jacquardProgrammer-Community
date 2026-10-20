"use client";

import { useDictionary } from "@/i18n/client";
import { useChatLiveOff } from "./chat-live";

/** Small, quiet note while live chat updates are not working (Phase 9c) — chat still works through the API. */
export function LiveOffHint({ className = "" }: { className?: string }) {
  const c = useDictionary().chat;
  const off = useChatLiveOff();
  if (!off) return null;
  return (
    <p className={`flex items-center gap-1.5 text-xs text-muted ${className}`} title={c.liveOffHint} role="status">
      <span className="size-2 shrink-0 rounded-full bg-muted/60" aria-hidden="true" />
      {c.liveOff}
      <span className="sr-only">— {c.liveOffHint}</span>
    </p>
  );
}
