"use client";

import Image from "next/image";
import { useEffect, useLayoutEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { useAuth } from "@/components/auth/auth-provider";
import { RequireAuth } from "@/components/auth/require-auth";
import { navItem } from "@/components/dashboard/dashboard-nav";
import { Avatar } from "@/components/profile/profile-view";
import { checkFile, DOCUMENT_MAX_BYTES, DOCUMENT_TYPES, failureText, formatSize } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Modal } from "@/components/ui/modal";
import { API_URL, apiDownload, apiFetch, type CommitteeChatMessage } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { chatTime } from "./chat-list";
import { onCommitteeChatLive, pollWhileChatOpen } from "./chat-live";
import { LiveOffHint } from "./chat-live-hint";
import { setCommitteeChatUnread } from "./committee-chat-unread";

/** Same limit as the backend (CommitteeChatMessage::MAX_LENGTH). */
const MESSAGE_MAX = 2000;

type Page = { data: CommitteeChatMessage[]; meta: { has_more: boolean; members: number; unread_total: number } };
type Load = { ok: true; messages: CommitteeChatMessage[]; hasMore: boolean; members: number } | { ok: false; text: string; canRetry: boolean };

const attachmentUrl = (id: number) => `${API_URL}/api/committee-chat/messages/${id}/attachment`;

/** Adds messages (by id, no duplicates), oldest first. */
function mergeMessages(list: CommitteeChatMessage[], incoming: CommitteeChatMessage[]): CommitteeChatMessage[] {
  const byId = new Map(list.map((m) => [m.id, m]));
  incoming.forEach((m) => byId.set(m.id, m));
  return [...byId.values()].sort((a, b) => a.id - b.id);
}

/** Marks the whole chat read and updates the menu badge. */
async function markRead(): Promise<void> {
  const res = await apiFetch<{ data: { unread_total: number } }>("/committee-chat/read", { method: "POST" });
  if (res.ok) setCommitteeChatUnread(res.data.data.unread_total);
}

/** /dashboard/committee-chat — the private group chat of the active committee + admins (the API checks access). */
export function CommitteeChatScreen() {
  return <RequireAuth roles={navItem("committeeChat").roles}>{() => <CommitteeChat />}</RequireAuth>;
}

function CommitteeChat() {
  const t = useDictionary();
  const c = t.committeeChat;
  const locale = useLocale();
  const me = useAuth().user?.id ?? 0;
  const [load, setLoad] = useState<Load | null>(null);
  const [retry, setRetry] = useState(0);
  const [older, setOlder] = useState<"idle" | "loading" | "failed">("idle");
  const [body, setBody] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [image, setImage] = useState<CommitteeChatMessage | null>(null);
  const scroller = useRef<HTMLDivElement>(null);
  const fileInput = useRef<HTMLInputElement>(null);
  const crop = useImageCrop();
  const scrollPlan = useRef<{ kind: "bottom" } | { kind: "keep"; fromBottom: number } | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<Page>("/committee-chat/messages").then(async (res) => {
      if (cancelled) return;
      if (!res.ok) {
        // 403 (not in the chat) cannot be fixed by trying again.
        setLoad({ ok: false, text: res.status === 403 ? c.forbidden : failureText(res, t), canRetry: res.status !== 403 });
        return;
      }
      scrollPlan.current = { kind: "bottom" };
      setLoad({ ok: true, messages: res.data.data, hasMore: res.data.meta.has_more, members: res.data.meta.members });
      // Opening the chat = everything in it is read.
      if (res.data.meta.unread_total > 0) await markRead();
    });
    return () => {
      cancelled = true;
    };
  }, [retry, t, c.forbidden]);

  // ---- live updates (own channel over Reverb; without the chat server the API screen still works) ----
  const ready = load?.ok === true;
  useEffect(() => {
    if (!ready) return;
    let readLater = false;
    const update = (fn: (l: Extract<Load, { ok: true }>) => Extract<Load, { ok: true }>) => setLoad((prev) => (prev?.ok ? fn(prev) : prev));
    const nearBottom = () => {
      const el = scroller.current;
      return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    };
    const readNow = () => {
      if (document.visibilityState === "visible") void markRead();
      else readLater = true;
    };
    const off = onCommitteeChatLive((e) => {
      if (e.type === "message") {
        const mine = e.message.sender.id === me;
        if (mine || nearBottom()) scrollPlan.current = { kind: "bottom" };
        update((l) => ({ ...l, messages: mergeMessages(l.messages, [{ ...e.message, mine }]) }));
        if (!mine) readNow();
      } else if (e.type === "resync") {
        // Back after the chat server was down: fetch the newest messages that may have been missed.
        void apiFetch<Page>("/committee-chat/messages").then((res) => {
          if (!res.ok) return;
          if (nearBottom()) scrollPlan.current = { kind: "bottom" };
          update((l) => ({ ...l, members: res.data.meta.members, messages: mergeMessages(l.messages, res.data.data) }));
          if (res.data.data.some((m) => !m.mine)) readNow();
        });
      }
    });
    const onVisible = () => {
      if (document.visibilityState === "visible" && readLater) {
        readLater = false;
        void markRead();
      }
    };
    document.addEventListener("visibilitychange", onVisible);
    const stopPolling = pollWhileChatOpen();
    return () => {
      off();
      stopPolling();
      document.removeEventListener("visibilitychange", onVisible);
    };
  }, [me, ready]);

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
        {load.canRetry && (
          <Button variant="outline" className="mt-5" onClick={() => setRetry((k) => k + 1)}>
            {c.retry}
          </Button>
        )}
      </Card>
    );

  const { messages, hasMore, members } = load;

  const loadOlder = async () => {
    if (!messages.length) return;
    setOlder("loading");
    const res = await apiFetch<Page>(`/committee-chat/messages?before=${messages[0].id}`);
    if (!res.ok) return setOlder("failed");
    const el = scroller.current;
    scrollPlan.current = { kind: "keep", fromBottom: el ? el.scrollHeight - el.scrollTop : 0 };
    setLoad((prev) => (prev?.ok ? { ...prev, messages: mergeMessages(prev.messages, res.data.data), hasMore: res.data.meta.has_more } : prev));
    setOlder("idle");
  };

  const pickFile = (e: React.ChangeEvent<HTMLInputElement>) => {
    const picked = e.target.files?.[0] ?? null;
    e.target.value = "";
    if (picked) crop.pick(picked, "document", acceptFile);
  };

  const acceptFile = (chosen: File) => {
    const problem = checkFile(chosen, DOCUMENT_TYPES, DOCUMENT_MAX_BYTES, { badType: c.badType, tooBig: c.tooBig });
    setError(problem);
    setFile(problem ? null : chosen);
  };

  const send = async (e?: React.FormEvent) => {
    e?.preventDefault();
    const text = body.trim();
    if ((!text && !file) || sending) return;
    if (text.length > MESSAGE_MAX) return setError(fill(c.tooLong, { max: localizeDigits(String(MESSAGE_MAX), locale) }));
    setError(null);
    setSending(true);
    const form = new FormData();
    if (text) form.append("body", text);
    if (file) form.append("attachment", file);
    const res = await apiFetch<{ data: CommitteeChatMessage }>("/committee-chat/messages", { method: "POST", body: form });
    setSending(false);
    if (!res.ok) {
      const errors = res.data?.errors;
      setError(errors?.body?.[0] ?? errors?.attachment?.[0] ?? failureText(res, t));
      return;
    }
    setBody("");
    setFile(null);
    scrollPlan.current = { kind: "bottom" };
    const sent = res.data.data;
    setLoad((prev) => (prev?.ok ? { ...prev, messages: mergeMessages(prev.messages, [sent]) } : prev));
  };

  const remaining = MESSAGE_MAX - body.length;

  return (
    <div className="mx-auto flex max-w-3xl flex-col gap-3">
      <Card className="p-3 sm:p-4">
        <p>{c.subtitle}</p>
        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-muted">
          <span>{fill(c.members, { n: localizeDigits(String(members), locale) })}</span>
          <LiveOffHint />
        </div>
      </Card>

      <Card className="p-0!">
        <div ref={scroller} className="h-[calc(100dvh-24rem)] min-h-72 space-y-3 overflow-y-auto px-3 py-4 sm:px-5" role="log" aria-label={c.logLabel}>
          {hasMore && (
            <div className="pb-2 text-center">
              <Button variant="outline" size="sm" disabled={older === "loading"} onClick={() => void loadOlder()}>
                {older === "loading" ? c.loadingOlder : c.loadOlder}
              </Button>
              {older === "failed" && <p className="mt-1 text-xs text-danger">{c.olderFailed}</p>}
            </div>
          )}
          {messages.length === 0 ? (
            <p className="py-10 text-center text-muted">{c.empty}</p>
          ) : (
            messages.map((m) => <Bubble key={m.id} m={m} mine={m.sender.id === me} onImage={() => setImage(m)} />)
          )}
        </div>
      </Card>

      <form onSubmit={send} className="space-y-1.5">
        {error && <Alert tone="danger">{error}</Alert>}
        {file && (
          <p className="flex items-center gap-2 rounded-control border border-border bg-surface px-3 py-2 text-sm">
            <Icon name="paperclip" className="size-4 shrink-0 text-muted" />
            <span className="min-w-0 flex-1 truncate">{file.name}</span>
            <span className="shrink-0 text-muted">{formatSize(file.size, locale)}</span>
            <button type="button" className="shrink-0 text-danger underline" onClick={() => setFile(null)}>
              {c.remove}
            </button>
          </p>
        )}
        <div className="flex items-end gap-2">
          <input ref={fileInput} type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" className="hidden" onChange={pickFile} />
          {crop.dialog}
          <Button type="button" variant="outline" className="h-12 shrink-0 px-3" aria-label={c.attach} title={`${c.attach} — ${c.attachHint}`} onClick={() => fileInput.current?.click()}>
            <Icon name="paperclip" className="size-5" />
          </Button>
          <label htmlFor="committee-chat-body" className="sr-only">
            {c.placeholder}
          </label>
          <textarea
            id="committee-chat-body"
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
          <Button type="submit" disabled={sending || (!body.trim() && !file)} className="h-12 shrink-0">
            <Icon name="send" className="size-4" />
            <span className="sr-only sm:not-sr-only">{sending ? c.sending : c.send}</span>
          </Button>
        </div>
        <p className={`text-right text-xs ${remaining < 0 ? "text-danger" : "text-muted"}`}>
          <span className="hidden sm:inline">{c.sendHint} · </span>
          {remaining < 200 && fill(c.left, { n: localizeDigits(String(remaining), locale) })}
        </p>
      </form>

      {image && <ImageModal message={image} onClose={() => setImage(null)} />}
    </div>
  );
}

function Bubble({ m, mine, onImage }: { m: CommitteeChatMessage; mine: boolean; onImage: () => void }) {
  const c = useDictionary().committeeChat;
  const locale = useLocale();
  const [failed, setFailed] = useState(false);
  const a = m.attachment;

  return (
    <div className={`flex items-end gap-2 ${mine ? "flex-row-reverse" : ""}`}>
      {!mine && <Avatar name={m.sender.name ?? "?"} url={m.sender.photo_url} className="size-8 shrink-0" rounded="rounded-full" />}
      <div className={`flex min-w-0 max-w-[85%] flex-col sm:max-w-[70%] ${mine ? "items-end" : "items-start"}`}>
        <div className={`rounded-2xl px-3.5 py-2 ${mine ? "rounded-br-md bg-primary text-white" : "rounded-bl-md bg-background text-foreground"}`}>
          {!mine && <p className="mb-0.5 text-xs font-semibold break-words text-primary">{m.sender.name ?? "—"}</p>}
          {m.body && <p className="wrap-anywhere whitespace-pre-line">{m.body}</p>}
          {a && (
            <div className={`${m.body ? "mt-2" : ""} flex flex-wrap items-center gap-2 text-sm`}>
              <span className="flex min-w-0 items-center gap-1.5">
                <Icon name={a.is_image ? "image" : "fileText"} className="size-4 shrink-0" />
                <span className="min-w-0 truncate">{a.name}</span>
                <span className={`shrink-0 text-xs ${mine ? "text-white/75" : "text-muted"}`}>{formatSize(a.size, locale)}</span>
              </span>
              <span className="flex gap-1.5">
                {a.is_image && (
                  <button type="button" onClick={onImage} className="rounded border border-current px-2 py-0.5 text-xs font-medium">
                    {c.open}
                  </button>
                )}
                <button
                  type="button"
                  onClick={() => void apiDownload(attachmentUrl(m.id), a.name).then((status) => setFailed(status !== 200))}
                  className="rounded border border-current px-2 py-0.5 text-xs font-medium"
                >
                  {c.download}
                </button>
              </span>
            </div>
          )}
          {failed && <p className={`mt-1 text-xs ${mine ? "text-white" : "text-danger"}`}>{c.downloadFailed}</p>}
          <p className={`mt-0.5 text-right text-[0.7rem] ${mine ? "text-white/75" : "text-muted"}`}>{chatTime(m.created_at, locale, true)}</p>
        </div>
      </div>
    </div>
  );
}

/** An image attachment (private file, loaded with the login cookie) in a dialog. */
function ImageModal({ message, onClose }: { message: CommitteeChatMessage; onClose: () => void }) {
  const c = useDictionary().committeeChat;
  const [src, setSrc] = useState<string | "failed" | null>(null);

  useEffect(() => {
    let cancelled = false;
    let objectUrl: string | null = null;
    fetch(attachmentUrl(message.id), { credentials: "include", cache: "no-store" })
      .then((res) => (res.ok ? res.blob() : null))
      .catch(() => null)
      .then((blob) => {
        if (cancelled) return;
        if (!blob) return setSrc("failed");
        objectUrl = URL.createObjectURL(blob);
        setSrc(objectUrl);
      });
    return () => {
      cancelled = true;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [message.id]);

  return (
    <Modal title={message.attachment?.name ?? c.imageTitle} onClose={onClose}>
      {src === null ? (
        <div role="status" className="flex aspect-[3/4] w-full animate-pulse items-center justify-center rounded-2xl bg-border text-sm text-muted">
          {c.loading}
        </div>
      ) : src === "failed" ? (
        <p className="text-danger">{c.imageFailed}</p>
      ) : (
        <Image src={src} alt={message.attachment?.name ?? c.imageTitle} width={800} height={1200} unoptimized className="mx-auto h-auto max-h-[70vh] w-auto max-w-full rounded-2xl border border-border object-contain" />
      )}
      <div className="mt-5 flex justify-end">
        <Button variant="outline" onClick={onClose}>
          {c.close}
        </Button>
      </div>
    </Modal>
  );
}
