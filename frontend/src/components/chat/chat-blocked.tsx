"use client";

import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar } from "@/components/profile/profile-view";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type ChatBlockedMember } from "@/lib/api";
import { fill, formatDate } from "@/lib/auth";
import { BlockDialog } from "./chat-safety";

/** /dashboard/chat/blocked — the members I blocked, with Unblock (Phase 9d-1). */
export function ChatBlockedScreen() {
  return <RequireAuth roles={navItem("chat").roles}>{() => <ChatBlocked />}</RequireAuth>;
}

type Load = { ok: true; list: ChatBlockedMember[] } | { ok: false };

function ChatBlocked() {
  const t = useDictionary();
  const locale = useLocale();
  const b = t.chatBlocked;
  const [load, setLoad] = useState<Load | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [unblocking, setUnblocking] = useState<ChatBlockedMember | null>(null);
  const [flash, setFlash] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: ChatBlockedMember[] }>("/chat/blocks").then((res) => {
      if (!cancelled) setLoad(res.ok ? { ok: true, list: res.data.data } : { ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [reloadKey]);

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <ButtonLink href="/dashboard/chat" variant="ghost" size="sm" className="px-2">
          <Icon name="arrowLeft" className="size-4" />
          {b.back}
        </ButtonLink>
      </div>
      <h2 className="text-xl font-semibold">{t.chat.blockedList}</h2>
      <p className="text-muted">{b.subtitle}</p>
      {flash && <Alert tone="success">{flash}</Alert>}

      {!load ? (
        <ul className="space-y-2" aria-busy="true">
          <li className="sr-only" role="status">
            {b.loading}
          </li>
          {[0, 1].map((i) => (
            <li key={i} className="h-18 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : !load.ok ? (
        <Card className="text-center">
          <p className="text-danger">{b.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {b.retry}
          </Button>
        </Card>
      ) : load.list.length === 0 ? (
        <Card className="p-6 text-center text-muted sm:p-8">{b.empty}</Card>
      ) : (
        <Card className="overflow-hidden p-0!">
          <ul>
            {load.list.map((row) => (
              <li key={row.user.id} className="flex items-center gap-3 border-b border-border px-4 py-3 last:border-b-0 sm:px-5">
                <Avatar name={row.user.name} url={row.user.photo_url} className="size-11" rounded="rounded-full" />
                <div className="min-w-0 flex-1">
                  <p className="truncate font-semibold text-heading">{row.user.name}</p>
                  <p className="text-xs text-muted">{fill(b.blockedOn, { date: formatDate(row.blocked_at, locale) })}</p>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  className="shrink-0"
                  onClick={() => {
                    setFlash(null);
                    setUnblocking(row);
                  }}
                >
                  {b.unblock}
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {unblocking && (
        <BlockDialog
          name={unblocking.user.name}
          userId={unblocking.user.id}
          block={false}
          onClose={() => setUnblocking(null)}
          onDone={() => {
            const id = unblocking.user.id;
            setUnblocking(null);
            setFlash(b.unblocked);
            setLoad((prev) => (prev?.ok ? { ok: true, list: prev.list.filter((r) => r.user.id !== id) } : prev));
          }}
        />
      )}
    </div>
  );
}
