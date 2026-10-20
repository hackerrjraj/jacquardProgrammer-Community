"use client";

import { useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { failureText, fieldErrors, formatSize } from "@/components/profile/profile-shared";
import { apiDownload, apiFetch, type AdminNotice, type ApiResult, type NoticeVisibility } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { BODY_MAX, NOTICE_MAX_BYTES, NOTICE_TYPES, TITLE_MAX } from "./notice-text";

type Draft = {
  title_bn: string;
  title_en: string;
  body_bn: string;
  body_en: string;
  visibility: NoticeVisibility;
  /** datetime-local value in Bangladesh time ("2026-10-14T10:30"), "" = none */
  published_at: string;
  is_pinned: boolean;
};
type Field = keyof Draft | "attachment";
type Saved = { message: string; data: AdminNotice };

/** ISO date → "YYYY-MM-DDTHH:mm" in Bangladesh time (for <input type="datetime-local">). */
function toDhakaInput(iso: string | null): string {
  if (!iso) return "";
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat("en-GB", {
      timeZone: "Asia/Dhaka",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      hourCycle: "h23",
    })
      .formatToParts(new Date(iso))
      .map((p) => [p.type, p.value]),
  );
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

function draftFrom(notice: AdminNotice | null): Draft {
  return {
    title_bn: notice?.title_bn ?? "",
    title_en: notice?.title_en ?? "",
    body_bn: notice?.body_bn ?? "",
    body_en: notice?.body_en ?? "",
    visibility: notice?.visibility ?? "public",
    published_at: toDhakaInput(notice?.published_at ?? null),
    is_pinned: notice?.is_pinned ?? false,
  };
}

/**
 * Create / edit a notice (admin). bn/en title + text, who can see it, publish date (future = scheduled), pin,
 * one attachment (PDF/JPG/PNG/WebP ≤ 5 MB, checked here first). Buttons: "Save as draft" and "Publish".
 * Editing uses the API's separate routes in order: text/visibility/date → attachment → pin → publish state.
 */
export function NoticeForm({
  notice,
  onDone,
  onCancel,
}: {
  /** null = new notice */
  notice: AdminNotice | null;
  /** Saved: back to the list with this message. */
  onDone: (message: string) => void;
  onCancel: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.notices.admin;
  const [current, setCurrent] = useState<AdminNotice | null>(notice);
  const [draft, setDraft] = useState<Draft>(() => draftFrom(notice));
  const [file, setFile] = useState<File | null>(null);
  const [removeFile, setRemoveFile] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const digits = (v: number) => localizeDigits(String(v), locale);

  const set = (patch: Partial<Draft>) => {
    setDraft((d) => ({ ...d, ...patch }));
    setErrors((e) => ({ ...e, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));
  };

  const crop = useImageCrop();

  function chooseFile(chosen: File | undefined) {
    if (fileInput.current) fileInput.current.value = "";
    if (!chosen) return;
    if (!NOTICE_TYPES.includes(chosen.type)) return setErrors((e) => ({ ...e, attachment: a.badType }));
    if (chosen.size > NOTICE_MAX_BYTES) return setErrors((e) => ({ ...e, attachment: a.tooBig }));
    setErrors((e) => ({ ...e, attachment: undefined }));
    setFile(chosen);
    setRemoveFile(false);
  }

  function validate(): boolean {
    const found: Partial<Record<Field, string>> = {};
    const tooLong = (max: number) => fill(a.tooLong, { max: digits(max) });
    if (!draft.title_bn.trim()) found.title_bn = a.required;
    else if (draft.title_bn.trim().length > TITLE_MAX) found.title_bn = tooLong(TITLE_MAX);
    if (draft.title_en.trim().length > TITLE_MAX) found.title_en = tooLong(TITLE_MAX);
    if (!draft.body_bn.trim()) found.body_bn = a.required;
    else if (draft.body_bn.trim().length > BODY_MAX) found.body_bn = tooLong(BODY_MAX);
    if (draft.body_en.trim().length > BODY_MAX) found.body_en = tooLong(BODY_MAX);
    if (errors.attachment) found.attachment = errors.attachment;
    setErrors(found);
    return Object.keys(found).length === 0;
  }

  /** 422 → messages under the fields; anything else → box. Returns true when the request worked. */
  function handle(res: ApiResult<unknown>): boolean {
    if (res.ok) return true;
    const fe = fieldErrors(res);
    const known = ["title_bn", "title_en", "body_bn", "body_en", "visibility", "published_at", "attachment"] as const;
    const mine = Object.fromEntries(known.filter((k) => fe[k]).map((k) => [k, fe[k]]));
    setErrors((e) => ({ ...e, ...mine }));
    setFormError(Object.keys(mine).length ? t.auth.fixErrors : failureText(res, t));
    return false;
  }

  async function create(publish: boolean) {
    const body = new FormData();
    body.append("title_bn", draft.title_bn.trim());
    body.append("title_en", draft.title_en.trim());
    body.append("body_bn", draft.body_bn.trim());
    body.append("body_en", draft.body_en.trim());
    body.append("visibility", draft.visibility);
    body.append("is_published", publish ? "1" : "0");
    body.append("is_pinned", draft.is_pinned ? "1" : "0");
    if (draft.published_at) body.append("published_at", draft.published_at);
    if (file) body.append("attachment", file);
    const res = await apiFetch<Saved>("/admin/notices", { method: "POST", body });
    if (handle(res) && res.ok) onDone(res.data.message);
  }

  /** "save" keeps the publish state, "publish" / "draft" change it. */
  async function update(base: AdminNotice, action: "save" | "publish" | "draft") {
    let latest = base;
    let stepDone = false;
    const step = async (res: ApiResult<Saved>): Promise<boolean> => {
      if (res.ok) {
        latest = res.data.data;
        setCurrent(latest);
        stepDone = true;
        return true;
      }
      handle(res);
      // Earlier steps were saved already — say so, so the admin is not surprised.
      if (stepDone) setFormError(fill(a.partlySaved, { error: failureText(res, t) }));
      return false;
    };

    const original = draftFrom(base);
    const patch: Record<string, string | null> = {};
    for (const key of ["title_bn", "title_en", "body_bn", "body_en"] as const) {
      if (draft[key].trim() !== original[key].trim()) patch[key] = draft[key].trim() || null;
    }
    if (draft.visibility !== original.visibility) patch.visibility = draft.visibility;
    if (draft.published_at !== original.published_at) patch.published_at = draft.published_at || null;

    if (Object.keys(patch).length && !(await step(await apiFetch<Saved>(`/admin/notices/${base.id}`, { method: "PATCH", body: patch })))) return;

    if (file) {
      const body = new FormData();
      body.append("attachment", file);
      if (!(await step(await apiFetch<Saved>(`/admin/notices/${base.id}/attachment`, { method: "POST", body })))) return;
      setFile(null);
    } else if (removeFile && latest.attachment) {
      if (!(await step(await apiFetch<Saved>(`/admin/notices/${base.id}/attachment`, { method: "DELETE" })))) return;
      setRemoveFile(false);
    }

    if (draft.is_pinned !== latest.is_pinned) {
      if (!(await step(await apiFetch<Saved>(`/admin/notices/${base.id}/${draft.is_pinned ? "pin" : "unpin"}`, { method: "POST" })))) return;
    }

    if ((action === "publish" && !latest.is_published) || (action === "draft" && latest.is_published)) {
      if (!(await step(await apiFetch<Saved>(`/admin/notices/${base.id}/${action === "publish" ? "publish" : "unpublish"}`, { method: "POST" })))) return;
    }

    onDone(a.saved);
  }

  async function submit(action: "save" | "publish" | "draft") {
    setFormError(null);
    if (!validate()) {
      setFormError(t.auth.fixErrors);
      return;
    }
    setBusy(true);
    if (current) await update(current, action);
    else await create(action === "publish");
    setBusy(false);
  }

  const existing = current?.attachment ?? null;
  const published = current?.is_published ?? false;

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{current ? a.editTitle : a.createTitle}</h2>
        <Button variant="ghost" size="sm" disabled={busy} onClick={onCancel}>
          <Icon name="arrowLeft" className="size-4" />
          {a.backToList}
        </Button>
      </div>

      <form
        noValidate
        className="mt-5 grid gap-5 lg:grid-cols-2"
        onSubmit={(e) => {
          // Enter key: never publishes by accident — keeps the current publish state (new notice → draft).
          e.preventDefault();
          void submit(current ? "save" : "draft");
        }}
      >
        <Input label={a.titleBn} value={draft.title_bn} onChange={(e) => set({ title_bn: e.target.value })} error={errors.title_bn} maxLength={TITLE_MAX} required />
        <Input label={a.titleEn} value={draft.title_en} onChange={(e) => set({ title_en: e.target.value })} error={errors.title_en} maxLength={TITLE_MAX} />
        <Textarea
          label={a.bodyBn}
          value={draft.body_bn}
          onChange={(e) => set({ body_bn: e.target.value })}
          error={errors.body_bn}
          maxLength={BODY_MAX}
          rows={10}
          required
        />
        <Textarea label={a.bodyEn} value={draft.body_en} onChange={(e) => set({ body_en: e.target.value })} error={errors.body_en} maxLength={BODY_MAX} rows={10} />
        <p className="-mt-2 text-sm text-muted lg:col-span-2">{a.englishNote}</p>

        <Select label={a.visibility} value={draft.visibility} onChange={(e) => set({ visibility: e.target.value as NoticeVisibility })} error={errors.visibility}>
          <option value="public">{a.visibilityPublicLong}</option>
          <option value="members">{a.visibilityMembersLong}</option>
        </Select>
        <div>
          <Input
            type="datetime-local"
            label={a.publishedAt}
            value={draft.published_at}
            onChange={(e) => set({ published_at: e.target.value })}
            error={errors.published_at}
          />
          <p className="mt-1 text-sm text-muted">{a.publishedAtHint}</p>
        </div>

        <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-heading lg:col-span-2">
          <input type="checkbox" checked={draft.is_pinned} onChange={(e) => set({ is_pinned: e.target.checked })} className="size-4 accent-(--color-secondary)" />
          {a.pinField}
        </label>

        <div className="lg:col-span-2">
          <p className="mb-1.5 block text-sm font-medium text-foreground">{a.attachmentField}</p>
          <p className="mb-3 text-sm text-muted">{a.attachmentHint}</p>
          {existing && !file && (
            <div className={`mb-3 flex flex-wrap items-center gap-3 rounded-control border border-border bg-background px-4 py-3 ${removeFile ? "opacity-60" : ""}`}>
              <Icon name="paperclip" className="size-5 shrink-0 text-primary-text" />
              <span className="min-w-0 flex-1 text-sm">
                <button
                  type="button"
                  className="block max-w-full truncate text-left font-medium text-secondary underline-offset-2 hover:underline"
                  onClick={() => void apiDownload(existing.url, existing.name)}
                >
                  {existing.name}
                </button>
                <span className="text-muted">{formatSize(existing.size, locale)}</span>
              </span>
              <Button variant={removeFile ? "outline" : "danger"} size="sm" disabled={busy} onClick={() => setRemoveFile((v) => !v)}>
                {removeFile ? a.undoRemove : a.removeFile}
              </Button>
            </div>
          )}
          {removeFile && <p className="mb-3 text-sm text-danger">{a.fileWillBeRemoved}</p>}
          {file && (
            <div className="mb-3 flex flex-wrap items-center gap-3 rounded-control border border-primary/40 bg-primary-soft/40 px-4 py-3 text-sm">
              <Icon name="paperclip" className="size-5 shrink-0 text-primary-text" />
              <span className="min-w-0 flex-1 truncate">{fill(a.newFile, { name: file.name })} · {formatSize(file.size, locale)}</span>
              <Button variant="ghost" size="sm" disabled={busy} onClick={() => setFile(null)}>
                {a.cancel}
              </Button>
            </div>
          )}
          <input
            ref={fileInput}
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
            className="sr-only"
            id="notice-attachment"
            onChange={(e) => {
              crop.pick(e.target.files?.[0], "document", chooseFile);
              e.target.value = "";
            }}
          />
          {crop.dialog}
          <Button variant="outline" size="sm" disabled={busy} onClick={() => fileInput.current?.click()}>
            <Icon name="upload" className="size-4" />
            {existing || file ? a.replaceFile : a.chooseFile}
          </Button>
          {errors.attachment && <p className="mt-2 text-sm text-danger">{errors.attachment}</p>}
        </div>

        {formError && (
          <Alert tone="danger" className="lg:col-span-2">
            {formError}
          </Alert>
        )}

        <div className="flex flex-col gap-3 border-t border-border pt-5 lg:col-span-2">
          {!published && <p className="text-sm text-muted">{a.draftNote}</p>}
          <div className="flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={onCancel}>
              {a.cancel}
            </Button>
            {published ? (
              <>
                <Button variant="outline" disabled={busy} onClick={() => void submit("draft")}>
                  {a.unpublishAndSave}
                </Button>
                <Button type="submit" disabled={busy}>
                  {busy ? a.saving : a.save}
                </Button>
              </>
            ) : (
              <>
                <Button variant="outline" disabled={busy} onClick={() => void submit(current ? "save" : "draft")}>
                  {busy ? a.saving : a.saveDraft}
                </Button>
                <Button disabled={busy} onClick={() => void submit("publish")}>
                  {busy ? a.saving : a.publishNow}
                </Button>
              </>
            )}
          </div>
        </div>
      </form>
    </Card>
  );
}
