"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import type { Locale } from "@/i18n/config";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar } from "@/components/profile/profile-view";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type ChatConversation, type ChatConversationPage } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { LiveOffHint } from "./chat-live-hint";
import { onChatLive } from "./chat-live";
import { setChatUnread } from "./chat-unread";

/** "14:05" for today, "16 Oct" this year, else "16 Oct 2025" — Dhaka time. */
export function chatTime(iso: string | null, locale: Locale, withTime = false): string {
  if (!iso) return "";
  const date = new Date(iso);
  const tag = locale === "bn" ? "bn-BD" : "en-GB";
  const zone = "Asia/Dhaka";
  const day = (d: Date) => new Intl.DateTimeFormat("en-CA", { timeZone: zone }).format(d);
  const rawTime = new Intl.DateTimeFormat(tag, { hour: "numeric", minute: "2-digit", timeZone: zone }).format(date);
  // Browsers write "৯:৪৩ AM" for Bengali — use Bengali words so the line is not half English (9d-2).
  const time = locale === "bn" ? rawTime.replace(/\bAM\b/, "পূর্বাহ্ণ").replace(/\bPM\b/, "অপরাহ্ণ") : rawTime;
  if (day(date) === day(new Date())) return time;
  const sameYear = day(date).slice(0, 4) === day(new Date()).slice(0, 4);
  const dateText = new Intl.DateTimeFormat(tag, { day: "numeric", month: "short", ...(sameYear ? {} : { year: "numeric" }), timeZone: zone }).format(date);
  return withTime ? `${dateText}, ${time}` : dateText;
}

/** /dashboard/chat — my conversations, newest activity first (Phase 9b). */
export function ChatListScreen() {
  return <RequireAuth roles={navItem("chat").roles}>{() => <ChatList />}</RequireAuth>;
}

type Load = { key: number; ok: true; page: ChatConversationPage } | { key: number; ok: false };

function ChatList() {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.chat;
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [load, setLoad] = useState<Load | null>(null);
  // Live updates (9c): reload the list quietly (no loading look) when a message arrives or is read.
  const [liveTick, setLiveTick] = useState(0);
  const digits = (n: number) => localizeDigits(String(n), locale);
  const key = page * 1000 + reloadKey;

  useEffect(() => {
    let cancelled = false;
    void apiFetch<ChatConversationPage>(`/chat/conversations?page=${page}`).then((res) => {
      if (cancelled) return;
      setLoad(res.ok ? { key, ok: true, page: res.data } : { key, ok: false });
      if (res.ok) setChatUnread(res.data.meta.unread_total);
    });
    return () => {
      cancelled = true;
    };
  }, [page, key, liveTick]);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const off = onChatLive(() => {
      clearTimeout(timer);
      timer = setTimeout(() => setLiveTick((n) => n + 1), 300);
    });
    return () => {
      off();
      clearTimeout(timer);
    };
  }, []);

  const list = load?.ok ? load.page : null;
  const stale = load?.key !== key;

  return (
    <div className="mx-auto max-w-3xl space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <p className="text-muted">{c.subtitle}</p>
        <div className="flex items-center gap-3">
          <LiveOffHint />
          {/* Members I blocked (Phase 9d-1) */}
          <ButtonLink href="/dashboard/chat/blocked" variant="ghost" size="sm" className="px-3">
            <Icon name="ban" className="size-4" />
            {c.blockedList}
          </ButtonLink>
        </div>
      </div>
      {load && !load.ok && !stale ? (
        <Card className="text-center">
          <p className="text-danger">{c.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {c.retry}
          </Button>
        </Card>
      ) : !list ? (
        <ul className="space-y-2" aria-busy="true">
          <li className="sr-only" role="status">
            {c.loading}
          </li>
          {[0, 1, 2, 3].map((i) => (
            <li key={i} className="h-20 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : list.data.length === 0 ? (
        <Card className="p-6 text-center sm:p-8">
          <Icon name="chat" className="mx-auto size-10 text-muted" />
          <p className="mt-3 font-semibold text-heading">{c.empty}</p>
          <p className="mt-1 text-sm text-muted">{c.emptyHint}</p>
          <ButtonLink href="/programmers" variant="outline" className="mt-5">
            <Icon name="users" className="size-4" />
            {c.findProgrammers}
          </ButtonLink>
        </Card>
      ) : (
        <div className={stale ? "opacity-60 transition-opacity" : undefined}>
          <Card className="overflow-hidden p-0!">
            <ul>
              {list.data.map((conv) => (
                <li key={conv.id} className="border-b border-border last:border-b-0">
                  <ConversationRow conv={conv} />
                </li>
              ))}
            </ul>
          </Card>
          {list.meta.last_page > 1 && (
            <nav className="mt-4 flex items-center justify-between gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1 || stale} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {c.prev}
              </Button>
              <span className="text-sm text-muted">{fill(c.page, { page: digits(page), last: digits(list.meta.last_page) })}</span>
              <Button variant="outline" size="sm" disabled={page >= list.meta.last_page || stale} onClick={() => setPage(page + 1)}>
                {c.next}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </nav>
          )}
        </div>
      )}
    </div>
  );
}

/** Small grey "Unavailable" pill for a partner who can no longer get messages (9d-2). */
export function UnavailableLabel() {
  const c = useDictionary().chat;
  return <span className="shrink-0 rounded-full bg-border/60 px-2 py-0.5 text-[0.7rem] font-semibold text-muted">{c.unavailable}</span>;
}

function ConversationRow({ conv }: { conv: ChatConversation }) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.chat;
  const unread = conv.unread_count > 0;
  const last = conv.last_message;
  const unreadText = localizeDigits(conv.unread_count > 99 ? "99+" : String(conv.unread_count), locale);
  const name = conv.partner.name ?? c.formerMember;

  return (
    <Link href={`/dashboard/chat/${conv.id}`} className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-background sm:px-5">
      <Avatar name={name} url={conv.partner.photo_url} className="size-12" rounded="rounded-full" />
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline justify-between gap-2">
          <div className="flex min-w-0 items-center gap-1.5">
            <p className={`truncate text-heading ${unread ? "font-bold" : "font-semibold"}`}>{name}</p>
            {/* Suspended / removed / demo / gone partner (9d-2) */}
            {!conv.partner_available && <UnavailableLabel />}
          </div>
          <span className={`shrink-0 text-xs ${unread ? "font-semibold text-primary-text" : "text-muted"}`}>
            {chatTime(conv.last_message_at, locale)}
          </span>
        </div>
        <div className="mt-0.5 flex items-center justify-between gap-2">
          <p className={`truncate text-sm ${unread ? "font-medium text-foreground" : "text-muted"}`}>
            {last ? (
              <>
                {last.mine && <span className="text-muted">{c.you} </span>}
                {last.body}
              </>
            ) : (
              <span className="italic">{c.noMessages}</span>
            )}
          </p>
          {unread && (
            <span className="shrink-0 rounded-full bg-primary px-2 py-0.5 text-xs font-bold text-white">
              <span aria-hidden="true">{unreadText}</span>
              <span className="sr-only">{fill(c.unread, { n: unreadText })}</span>
            </span>
          )}
        </div>
      </div>
    </Link>
  );
}
