"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { failureText } from "@/components/profile/profile-shared";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Icon } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type ChatReportReason } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";

// Chat block + report (Phase 9d-1).

/** Same limit as the backend (ChatReport::NOTE_MAX). */
const NOTE_MAX = 500;
const REASONS: ChatReportReason[] = ["harassment", "spam", "fraud_or_money_request", "other"];

/** Block (POST) or unblock (DELETE) a member; returns an error text or null. */
export async function setBlocked(userId: number, blocked: boolean, t: ReturnType<typeof useDictionary>): Promise<string | null> {
  const res = blocked
    ? await apiFetch("/chat/blocks", { method: "POST", body: { user_id: userId } })
    : await apiFetch(`/chat/blocks/${userId}`, { method: "DELETE" });
  return res.ok ? null : failureText(res, t);
}

/** "⋮" menu on the chat screen: Block / Unblock + link to the blocked members list. */
export function ChatMenu({ blockedByMe, onToggleBlock }: { blockedByMe: boolean; onToggleBlock: () => void }) {
  const c = useDictionary().chat;
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onDown = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setOpen(false);
    };
    document.addEventListener("mousedown", onDown);
    document.addEventListener("keydown", onKey);
    return () => {
      document.removeEventListener("mousedown", onDown);
      document.removeEventListener("keydown", onKey);
    };
  }, [open]);

  const item = "flex w-full items-center gap-2.5 rounded-control px-3 py-2.5 text-left text-sm hover:bg-background";

  return (
    <div ref={ref} className="relative shrink-0">
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label={c.menu}
        title={c.menu}
        className="flex size-10 items-center justify-center rounded-full text-foreground hover:bg-primary-soft hover:text-primary-text"
      >
        <Icon name="more" className="size-6" />
      </button>
      {open && (
        <div role="menu" className="absolute right-0 z-30 mt-1 w-56 rounded-card border border-border bg-surface p-1.5 shadow-panel">
          <button
            role="menuitem"
            type="button"
            onClick={() => {
              setOpen(false);
              onToggleBlock();
            }}
            className={`${item} ${blockedByMe ? "text-foreground hover:text-primary-text" : "text-danger"}`}
          >
            <Icon name="ban" className="size-4" />
            {blockedByMe ? c.unblock : c.block}
          </button>
          <Link role="menuitem" href="/dashboard/chat/blocked" className={`${item} text-foreground hover:text-primary-text`}>
            <Icon name="users" className="size-4" />
            {c.blockedList}
          </Link>
        </div>
      )}
    </div>
  );
}

/** Confirm dialog for block / unblock. */
export function BlockDialog({
  name,
  userId,
  block,
  onClose,
  onDone,
}: {
  name: string;
  userId: number;
  block: boolean;
  onClose: () => void;
  onDone: () => void;
}) {
  const t = useDictionary();
  const c = t.chat;
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const confirm = async () => {
    setBusy(true);
    setError(null);
    const failed = await setBlocked(userId, block, t);
    setBusy(false);
    if (failed) return setError(failed);
    onDone();
  };

  return (
    <Modal title={fill(block ? c.blockTitle : c.unblockTitle, { name })} onClose={busy ? () => {} : onClose}>
      <p className="text-sm text-foreground">{fill(block ? c.blockText : c.unblockText, { name })}</p>
      {error && (
        <Alert tone="danger" className="mt-3">
          {error}
        </Alert>
      )}
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        <Button variant="ghost" onClick={onClose} disabled={busy}>
          {c.cancel}
        </Button>
        <Button variant={block ? "danger" : "primary"} onClick={() => void confirm()} disabled={busy}>
          {busy ? c.working : block ? c.confirmBlock : c.confirmUnblock}
        </Button>
      </div>
    </Modal>
  );
}

/** Report one received message: reason + optional note. */
export function ReportDialog({
  messageId,
  preview,
  onClose,
  onDone,
}: {
  messageId: number;
  preview: string;
  onClose: () => void;
  onDone: (message: string) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const c = t.chat;
  const [reason, setReason] = useState<ChatReportReason | "">("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!reason) return setError(c.reportNeedReason);
    setBusy(true);
    setError(null);
    const res = await apiFetch<{ message: string }>(`/chat/messages/${messageId}/report`, {
      method: "POST",
      body: { reason, note: note.trim() || null },
    });
    setBusy(false);
    if (!res.ok) return setError(res.data?.errors?.reason?.[0] ?? res.data?.errors?.note?.[0] ?? failureText(res, t));
    onDone(res.data.message);
  };

  return (
    <Modal title={c.reportTitle} onClose={busy ? () => {} : onClose}>
      <form onSubmit={submit} className="space-y-4">
        <blockquote className="max-h-32 overflow-y-auto rounded-control border-l-4 border-border bg-background px-3 py-2 text-sm wrap-anywhere whitespace-pre-line text-foreground">
          {preview}
        </blockquote>
        <p className="text-sm text-muted">{c.reportText}</p>
        <Select label={c.reportReason} value={reason} onChange={(e) => setReason(e.target.value as ChatReportReason | "")} required>
          <option value="">{c.reportChoose}</option>
          {REASONS.map((r) => (
            <option key={r} value={r}>
              {c.reasons[r]}
            </option>
          ))}
        </Select>
        <div>
          <Textarea
            label={c.reportNote}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            maxLength={NOTE_MAX}
            rows={3}
            className="[&_textarea]:min-h-20"
          />
          <p className="mt-1 text-right text-xs text-muted">{fill(c.reportNoteHint, { max: localizeDigits(String(NOTE_MAX), locale) })}</p>
        </div>
        {error && <Alert tone="danger">{error}</Alert>}
        <div className="flex flex-wrap justify-end gap-2">
          <Button type="button" variant="ghost" onClick={onClose} disabled={busy}>
            {c.cancel}
          </Button>
          <Button type="submit" variant="danger" disabled={busy}>
            <Icon name="flag" className="size-4" />
            {busy ? c.working : c.sendReport}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
