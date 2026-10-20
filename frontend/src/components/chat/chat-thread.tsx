"use client";

import { useParams } from "next/navigation";
import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar } from "@/components/profile/profile-view";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type ChatConversation, type ChatMessageItem } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { chatTime, UnavailableLabel } from "./chat-list";
import { chatEcho, MESSAGE_EVENT, onChatLive, pollWhileChatOpen, READ_EVENT, type ChatLiveMessage, type ChatLiveRead } from "./chat-live";
import { LiveOffHint } from "./chat-live-hint";
import { BlockDialog, ChatMenu, ReportDialog } from "./chat-safety";
import { setChatUnread } from "./chat-unread";

/** Same limit as the backend (ChatMessage::MAX_LENGTH). */
export const MESSAGE_MAX = 2000;

type MessagesPage = { data: ChatMessageItem[]; meta: { has_more: boolean; partner_last_read_id: number | null } };
type Load = { ok: true; conv: ChatConversation; messages: ChatMessageItem[]; hasMore: boolean } | { ok: false; text: string; canRetry: boolean };
type LoadedState = Extract<Load, { ok: true }>;

/** Adds messages (by id, no duplicates), oldest first. */
function mergeMessages(list: ChatMessageItem[], incoming: ChatMessageItem[]): ChatMessageItem[] {
  const byId = new Map(list.map((m) => [m.id, m]));
  incoming.forEach((m) => byId.set(m.id, m));
  return [...byId.values()].sort((a, b) => a.id - b.id);
}

/** My messages up to `upTo` are read by the partner (✓✓). */
function markMineRead(list: ChatMessageItem[], upTo: number): ChatMessageItem[] {
  return list.map((m) => (m.mine && !m.read && m.id <= upTo ? { ...m, read: true } : m));
}

/** Marks the whole conversation read and updates the menu badge. */
async function markConversationRead(id: number): Promise<void> {
  const read = await apiFetch<{ data: { unread_total: number } }>(`/chat/conversations/${id}/read`, { method: "POST" });
  if (read.ok) setChatUnread(read.data.data.unread_total);
}

/** /dashboard/chat/[id] — reads the id from the URL. */
export function ChatThreadPage() {
  const { id } = useParams<{ id: string }>();
  return <RequireAuth roles={navItem("chat").roles}>{() => <ChatThread id={Number(id)} />}</RequireAuth>;
}

/**
 * One conversation (Phase 9b): 30 messages at a time with "Load older", send box, read ticks.
 * Phase 9c: new messages and ✓✓ arrive live over the conversation channel; without the chat server it works as in 9b.
 * Phase 9d-1: "⋮" menu with Block / Unblock, "Report" on each received message; while blocked the send box is hidden.
 * Phase 9d-2: suspended / removed / demo / gone partner → "Unavailable" label + calm notice instead of the send box.
 */
function ChatThread({ id }: { id: number }) {
  const t = useDictionary();
  const me = useAuth().user?.id ?? 0;
  const locale = useLocale();
  const c = t.chat;
  const [load, setLoad] = useState<Load | null>(null);
  const [retry, setRetry] = useState(0);
  const [older, setOlder] = useState<"idle" | "loading" | "failed">("idle");
  const [body, setBody] = useState("");
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<{ kind: "block" | "unblock" } | { kind: "report"; m: ChatMessageItem } | null>(null);
  const [flash, setFlash] = useState<string | null>(null);
  const scroller = useRef<HTMLDivElement>(null);
  // How to place the scroll after the next render: stick to the bottom, or keep the reading position after "Load older".
  const scrollPlan = useRef<{ kind: "bottom" } | { kind: "keep"; fromBottom: number } | null>(null);

  useEffect(() => {
    let cancelled = false;
    void Promise.all([
      apiFetch<{ data: ChatConversation }>(`/chat/conversations/${id}`),
      apiFetch<MessagesPage>(`/chat/conversations/${id}/messages`),
    ]).then(async ([conv, msgs]) => {
      if (cancelled) return;
      if (!conv.ok || !msgs.ok) {
        const failed = conv.ok ? msgs : conv;
        // 403 (not your chat) / 404 cannot be fixed by trying again.
        setLoad({ ok: false, text: failureText(failed, t), canRetry: failed.status !== 403 && failed.status !== 404 });
        return;
      }
      scrollPlan.current = { kind: "bottom" };
      setLoad({ ok: true, conv: conv.data.data, messages: msgs.data.data, hasMore: msgs.data.meta.has_more });
      // Opening the conversation = everything in it is read.
      if (conv.data.data.unread_count > 0) await markConversationRead(id);
    });
    return () => {
      cancelled = true;
    };
  }, [id, retry, t]);

  // ---- live updates (9c) ----
  const ready = load?.ok === true;
  useEffect(() => {
    if (!ready) return;
    let readLater = false;
    const update = (fn: (l: LoadedState) => LoadedState) => setLoad((prev) => (prev?.ok ? fn(prev) : prev));
    const nearBottom = () => {
      const el = scroller.current;
      return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    };
    // A message from the partner while the chat is open counts as read — but only while the page is visible.
    const readNow = () => {
      if (document.visibilityState === "visible") void markConversationRead(id);
      else readLater = true;
    };

    const onMessage = ({ conversation_id, message }: ChatLiveMessage) => {
      if (conversation_id !== id) return;
      const mine = message.sender_id === me;
      if (mine || nearBottom()) scrollPlan.current = { kind: "bottom" };
      update((l) => ({
        ...l,
        messages: mergeMessages(l.messages, [{ id: message.id, body: message.body, mine, read: mine ? false : null, created_at: message.created_at }]),
      }));
      if (!mine) readNow();
    };
    const onRead = ({ conversation_id, reader_id, last_read_id }: ChatLiveRead) => {
      if (conversation_id !== id || reader_id === me) return;
      update((l) => ({ ...l, messages: markMineRead(l.messages, last_read_id) }));
    };
    // Back after the chat server was down: fetch the newest messages + ticks that may have been missed.
    const resync = async () => {
      const res = await apiFetch<MessagesPage>(`/chat/conversations/${id}/messages`);
      if (!res.ok) return;
      const fresh = res.data.data;
      const partnerRead = res.data.meta.partner_last_read_id ?? 0;
      if (nearBottom()) scrollPlan.current = { kind: "bottom" };
      update((l) => {
        const lastKnown = l.messages.at(-1)?.id ?? 0;
        // More than one page was missed → start again from the newest page.
        const gap = res.data.meta.has_more && fresh.length > 0 && fresh[0].id > lastKnown;
        const messages = gap ? fresh : mergeMessages(l.messages, fresh);
        return { ...l, hasMore: gap || l.hasMore, messages: markMineRead(messages, partnerRead) };
      });
      // Anything from the partner in the newest page → mark read (the server ignores it when nothing is new).
      if (fresh.some((m) => !m.mine)) readNow();
    };
    const onVisible = () => {
      if (document.visibilityState === "visible" && readLater) {
        readLater = false;
        void markConversationRead(id);
      }
    };

    const echo = chatEcho();
    const channel = `chat.conversation.${id}`;
    echo?.private(channel).listen(MESSAGE_EVENT, onMessage).listen(READ_EVENT, onRead);
    const off = onChatLive((e) => {
      if (e.type === "resync") void resync();
    });
    document.addEventListener("visibilitychange", onVisible);
    const stopPolling = pollWhileChatOpen();
    return () => {
      echo?.leave(channel);
      off();
      stopPolling();
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [id, me, ready]);

  useLayoutEffect(() => {
    const el = scroller.current;
    const plan = scrollPlan.current;
    if (!el || !plan) return;
    el.scrollTop = plan.kind === "bottom" ? el.scrollHeight : el.scrollHeight - plan.fromBottom;
    scrollPlan.current = null;
  });

  if (!load)
    return (
      <div className="mx-auto max-w-3xl" aria-busy="true">
        <p role="status" className="sr-only">
          {c.loading}
        </p>
        <div className="h-16 animate-pulse rounded-card bg-border/60" />
        <div className="mt-3 h-96 animate-pulse rounded-card bg-border/60" />
      </div>
    );

  if (!load.ok)
    return (
      <Card className="mx-auto max-w-lg text-center">
        <p className="text-danger">{load.text}</p>
        <div className="mt-5 flex flex-wrap justify-center gap-2">
          {load.canRetry && (
            <Button variant="outline" onClick={() => setRetry((k) => k + 1)}>
              {c.retry}
            </Button>
          )}
          <ButtonLink href="/dashboard/chat" variant={load.canRetry ? "ghost" : "outline"}>
            {c.back}
          </ButtonLink>
        </div>
      </Card>
    );

  const { conv, messages, hasMore } = load;
  const partnerName = conv.partner.name ?? c.formerMember;

  // Fresh block / send state after block, unblock or a refused send (9d-1).
  const reloadConversation = async (): Promise<boolean> => {
    const res = await apiFetch<{ data: ChatConversation }>(`/chat/conversations/${id}`);
    if (res.ok) setLoad((prev) => (prev?.ok ? { ...prev, conv: res.data.data } : prev));
    return res.ok;
  };

  const loadOlder = async () => {
    if (!messages.length) return;
    setOlder("loading");
    const res = await apiFetch<MessagesPage>(`/chat/conversations/${id}/messages?before=${messages[0].id}`);
    if (!res.ok) return setOlder("failed");
    const el = scroller.current;
    scrollPlan.current = { kind: "keep", fromBottom: el ? el.scrollHeight - el.scrollTop : 0 };
    setLoad((prev) => (prev?.ok ? { ...prev, messages: mergeMessages(prev.messages, res.data.data), hasMore: res.data.meta.has_more } : prev));
    setOlder("idle");
  };

  const send = async (e?: React.FormEvent) => {
    e?.preventDefault();
    const text = body.trim();
    if (!text || sending) return;
    if (text.length > MESSAGE_MAX) return setError(fill(c.tooLong, { max: localizeDigits(String(MESSAGE_MAX), locale) }));
    setError(null);
    setSending(true);
    const res = await apiFetch<{ data: ChatMessageItem }>(`/chat/conversations/${id}/messages`, { method: "POST", body: { body: text } });
    setSending(false);
    if (!res.ok) {
      setError(res.data?.errors?.body?.[0] ?? failureText(res, t));
      // A block in either direction (9d-1) or the partner became unavailable (9d-2) → reload, so the right notice replaces the box.
      // If even that fails (e.g. my own account was suspended — the server ended the session), the server message stays.
      if (res.status === 403 && (await reloadConversation())) setError(null);
      return;
    }
    setBody("");
    scrollPlan.current = { kind: "bottom" };
    // The live event for this message may have come first — no duplicate, keep a ✓✓ it may already have.
    const sent = res.data.data;
    setLoad((prev) => (prev?.ok ? { ...prev, messages: prev.messages.some((m) => m.id === sent.id) ? prev.messages : mergeMessages(prev.messages, [sent]) } : prev));
  };

  const remaining = MESSAGE_MAX - body.length;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-3">
      {/* Partner */}
      <Card className="flex items-center gap-3 p-3 sm:p-4">
        <ButtonLink href="/dashboard/chat" variant="ghost" size="sm" className="shrink-0 px-2" aria-label={c.back}>
          <Icon name="arrowLeft" className="size-5" />
        </ButtonLink>
        <Avatar name={partnerName} url={conv.partner.photo_url} className="size-10" rounded="rounded-full" />
        <div className="min-w-0 flex-1">
          <h2 className="truncate text-lg font-semibold">{partnerName}</h2>
          {/* Second line, so the name keeps the width on a phone. */}
          <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 empty:hidden">
            {!conv.partner_available && <UnavailableLabel />}
            <LiveOffHint />
          </div>
        </div>
        {/* No block menu when the account no longer exists (nothing to block). */}
        {conv.partner.name !== null && (
          <ChatMenu blockedByMe={conv.blocked_by_me} onToggleBlock={() => setDialog({ kind: conv.blocked_by_me ? "unblock" : "block" })} />
        )}
      </Card>
      {flash && <Alert tone="success">{flash}</Alert>}

      {/* Messages */}
      <Card className="p-0!">
        <div
          ref={scroller}
          className="h-[calc(100dvh-22rem)] min-h-72 space-y-2 overflow-y-auto px-3 py-4 sm:px-5"
          role="log"
          aria-label={fill(c.logLabel, { name: partnerName })}
        >
          {hasMore && (
            <div className="pb-2 text-center">
              <Button variant="outline" size="sm" disabled={older === "loading"} onClick={() => void loadOlder()}>
                {older === "loading" ? c.loadingOlder : c.loadOlder}
              </Button>
              {older === "failed" && <p className="mt-1 text-xs text-danger">{c.olderFailed}</p>}
            </div>
          )}
          {messages.length === 0 ? (
            <p className="py-10 text-center text-muted">{c.noMessagesYet}</p>
          ) : (
            messages.map((m) => (
              <Bubble
                key={m.id}
                m={m}
                onReport={() => {
                  setFlash(null);
                  setDialog({ kind: "report", m });
                }}
              />
            ))
          )}
        </div>
      </Card>

      {/* Send box — hidden while blocked; the blocked side only sees a neutral notice. */}
      {conv.blocked_by_me ? (
        <Alert tone="warning" className="flex flex-wrap items-center justify-between gap-2">
          <span>{c.youBlocked}</span>
          <Button variant="outline" size="sm" onClick={() => setDialog({ kind: "unblock" })}>
            {c.unblock}
          </Button>
        </Alert>
      ) : conv.blocked_me ? (
        <Alert tone="warning">{c.cannotReply}</Alert>
      ) : conv.can_send ? (
        <form onSubmit={send} className="space-y-1.5">
          {error && <Alert tone="danger">{error}</Alert>}
          <div className="flex items-end gap-2">
            <label htmlFor="chat-body" className="sr-only">
              {c.placeholder}
            </label>
            <textarea
              id="chat-body"
              value={body}
              onChange={(e) => setBody(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) void send();
              }}
              rows={2}
              maxLength={MESSAGE_MAX + 100}
              placeholder={c.placeholder}
              className="min-h-12 w-full flex-1 resize-y rounded-control border border-field-border bg-field px-3 py-2.5 text-base text-foreground placeholder:text-muted focus:bg-surface focus:outline-2 focus:outline-primary"
            />
            <Button type="submit" disabled={sending || !body.trim()} className="h-12 shrink-0">
              <Icon name="send" className="size-4" />
              <span className="sr-only sm:not-sr-only">{sending ? c.sending : c.send}</span>
            </Button>
          </div>
          <p className={`text-right text-xs ${remaining < 0 ? "text-danger" : "text-muted"}`}>
            <span className="hidden sm:inline">{c.sendHint} · </span>
            {remaining < 200 && fill(c.left, { n: localizeDigits(String(remaining), locale) })}
          </p>
        </form>
      ) : (
        <Alert tone="warning">{c.cannotSend}</Alert>
      )}

      {(dialog?.kind === "block" || dialog?.kind === "unblock") && (
        <BlockDialog
          name={partnerName}
          userId={conv.partner.id}
          block={dialog.kind === "block"}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            setFlash(null);
            void reloadConversation();
          }}
        />
      )}
      {dialog?.kind === "report" && (
        <ReportDialog
          messageId={dialog.m.id}
          preview={dialog.m.body}
          onClose={() => setDialog(null)}
          onDone={(message) => {
            const reportedId = dialog.m.id;
            setDialog(null);
            setFlash(message);
            setLoad((prev) => (prev?.ok ? { ...prev, messages: prev.messages.map((x) => (x.id === reportedId ? { ...x, reported: true } : x)) } : prev));
          }}
        />
      )}
    </div>
  );
}

function Bubble({ m, onReport }: { m: ChatMessageItem; onReport: () => void }) {
  const c = useDictionary().chat;
  const locale = useLocale();
  return (
    <div className={`flex flex-col ${m.mine ? "items-end" : "items-start"}`}>
      <div
        className={`max-w-[85%] rounded-2xl px-3.5 py-2 sm:max-w-[70%] ${
          m.mine ? "rounded-br-md bg-primary text-white" : "rounded-bl-md bg-background text-foreground"
        }`}
      >
        <p className="wrap-anywhere whitespace-pre-line">{m.body}</p>
        <p className={`mt-0.5 flex items-center justify-end gap-1 text-[0.7rem] ${m.mine ? "text-white/75" : "text-muted"}`}>
          {chatTime(m.created_at, locale, true)}
          {m.mine && (
            <span title={m.read ? c.read : c.sent} className={m.read ? "font-bold text-white" : undefined}>
              <span aria-hidden="true">{m.read ? "✓✓" : "✓"}</span>
              <span className="sr-only">{m.read ? c.read : c.sent}</span>
            </span>
          )}
        </p>
      </div>
      {/* Report — only the partner's messages (9d-1) */}
      {!m.mine &&
        (m.reported ? (
          <span className="mt-0.5 flex items-center gap-1 px-1 text-[0.7rem] text-muted">
            <Icon name="flag" className="size-3" />
            {c.reported}
          </span>
        ) : (
          <button
            type="button"
            onClick={onReport}
            className="mt-0.5 flex items-center gap-1 rounded px-1 py-0.5 text-[0.7rem] text-muted hover:text-danger focus-visible:text-danger"
          >
            <Icon name="flag" className="size-3" />
            {c.report}
          </button>
        ))}
    </div>
  );
}
