"use client";

import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { Select } from "@/components/ui/select";
import { apiUpload, type GalleryCategory } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { checkFile, failureText, fieldErrors, formatSize } from "@/components/profile/profile-shared";
import { GALLERY_MAX_BYTES, GALLERY_MAX_FILES, GALLERY_TYPES, categoryName } from "./gallery-shared";

type QueueItem = {
  id: number;
  file: File;
  preview: string;
  state: "ready" | "invalid" | "sending" | "done" | "failed";
  progress: number;
  error?: string;
};
type Flash = { tone: "success" | "danger" | "warning"; text: string } | null;

let nextId = 1;

/**
 * Multi-photo upload (up to 20 at a time). Type and 5 MB size are checked in the browser first;
 * then each photo is sent on its own request, so every file gets its own progress bar and error.
 * Photos are sent last-to-first: new uploads go on top, so the first chosen photo ends up first.
 */
export function GalleryUploader({
  categories,
  onUploaded,
}: {
  categories: GalleryCategory[];
  onUploaded: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.gallery.admin;
  const digits = (n: number | string) => localizeDigits(String(n), locale);

  const [queue, setQueue] = useState<QueueItem[]>([]);
  const [category, setCategory] = useState("");
  const [categoryError, setCategoryError] = useState<string>();
  const [titleBn, setTitleBn] = useState("");
  const [titleEn, setTitleEn] = useState("");
  const [publish, setPublish] = useState(true);
  const [running, setRunning] = useState(false);
  const [run, setRun] = useState({ processed: 0, total: 0 });
  const [flash, setFlash] = useState<Flash>(null);

  // Free the preview images when the screen closes.
  const queueRef = useRef(queue);
  useEffect(() => {
    queueRef.current = queue;
  }, [queue]);
  useEffect(() => () => queueRef.current.forEach((q) => URL.revokeObjectURL(q.preview)), []);

  const update = (id: number, patch: Partial<QueueItem>) =>
    setQueue((list) => list.map((q) => (q.id === id ? { ...q, ...patch } : q)));

  const crop = useImageCrop();

  function choose(e: React.ChangeEvent<HTMLInputElement>) {
    const files = Array.from(e.target.files ?? []);
    e.target.value = "";
    if (!files.length) return;
    const room = GALLERY_MAX_FILES - queue.length;
    const taken = files.slice(0, Math.max(0, room));
    setFlash(files.length > room ? { tone: "warning", text: fill(a.tooManyFiles, { max: digits(GALLERY_MAX_FILES) }) } : null);
    // Each picture is cropped (4:3) before it joins the queue.
    crop.pick(taken, "gallery", (file) => {
      const problem = checkFile(file, GALLERY_TYPES, GALLERY_MAX_BYTES, { badType: a.badType, tooBig: a.tooBig });
      const item: QueueItem = {
        id: nextId++,
        file,
        preview: URL.createObjectURL(file),
        state: problem ? "invalid" : "ready",
        progress: 0,
        error: problem ?? undefined,
      };
      setQueue((list) => (list.length >= GALLERY_MAX_FILES ? list : [...list, item]));
    });
  }

  function remove(item: QueueItem) {
    URL.revokeObjectURL(item.preview);
    setQueue((list) => list.filter((q) => q.id !== item.id));
  }

  function clear() {
    queue.forEach((q) => URL.revokeObjectURL(q.preview));
    setQueue([]);
    setFlash(null);
  }

  const sendable = queue.filter((q) => q.state === "ready" || q.state === "failed");

  async function start() {
    setFlash(null);
    if (!category) {
      setCategoryError(t.auth.errors.required);
      return;
    }
    if (!sendable.length) {
      setFlash({ tone: "danger", text: a.noValid });
      return;
    }
    setRunning(true);
    setRun({ processed: 0, total: sendable.length });
    let ok = 0;
    let stopped = false;
    for (const item of [...sendable].reverse()) {
      if (stopped) break;
      update(item.id, { state: "sending", progress: 0, error: undefined });
      const body = new FormData();
      body.append("images[]", item.file);
      body.append("category", category);
      if (titleBn.trim()) body.append("title_bn", titleBn.trim());
      if (titleEn.trim()) body.append("title_en", titleEn.trim());
      body.append("is_published", publish ? "1" : "0");
      const res = await apiUpload<{ message: string }>("/admin/gallery/images", body, (p) => update(item.id, { progress: p }));
      setRun((r) => ({ ...r, processed: r.processed + 1 }));
      if (res.ok) {
        ok++;
        update(item.id, { state: "done", progress: 100 });
        continue;
      }
      const errors = fieldErrors(res);
      if (errors.category) setCategoryError(errors.category);
      // Logged out / no permission / a field that affects every photo → stop trying the rest.
      if (res.status === 401 || res.status === 403 || errors.category || errors.title_bn || errors.title_en) stopped = true;
      update(item.id, {
        state: "failed",
        error: errors["images.0"] ?? errors.images ?? errors.title_bn ?? errors.title_en ?? errors.category ?? failureText(res, t),
      });
    }
    setRunning(false);

    // Uploaded photos leave the list; failed ones stay with their error so they can be tried again.
    setQueue((list) => {
      list.filter((q) => q.state === "done").forEach((q) => URL.revokeObjectURL(q.preview));
      return list.filter((q) => q.state !== "done");
    });
    if (ok > 0) onUploaded();
    const notSent = sendable.length - ok;
    setFlash(
      notSent === 0
        ? { tone: "success", text: fill(a.summaryOk, { ok: digits(ok) }) }
        : { tone: ok > 0 ? "warning" : "danger", text: fill(a.summary, { ok: digits(ok), failed: digits(notSent) }) },
    );
  }

  const statusText = (q: QueueItem) =>
    q.state === "sending"
      ? fill(a.sending, { p: digits(q.progress) })
      : q.state === "done"
        ? a.done
        : q.state === "ready"
          ? a.waiting
          : (q.error ?? a.failed);

  return (
    <Card className="p-5 sm:p-6">
      {crop.dialog}
      <h2 className="text-lg font-semibold">{a.uploadTitle}</h2>
      <p className="mt-1 text-sm text-muted">{fill(a.uploadHint, { max: digits(GALLERY_MAX_FILES) })}</p>

      <div className="mt-5 grid gap-4 md:grid-cols-3">
        <Select
          label={a.category}
          value={category}
          error={categoryError}
          disabled={running}
          onChange={(e) => {
            setCategory(e.target.value);
            setCategoryError(undefined);
          }}
        >
          <option value="">{a.chooseCategory}</option>
          {categories.map((c) => (
            <option key={c.key} value={c.key}>
              {categoryName(c, locale)}
            </option>
          ))}
        </Select>
        <Input label={a.titleBn} value={titleBn} onChange={(e) => setTitleBn(e.target.value)} maxLength={150} disabled={running} />
        <Input label={a.titleEn} value={titleEn} onChange={(e) => setTitleEn(e.target.value)} maxLength={150} disabled={running} />
      </div>
      <p className="mt-2 text-sm text-muted">{a.titleNote}</p>
      <label className="mt-4 inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-heading">
        <input
          type="checkbox"
          checked={publish}
          onChange={(e) => setPublish(e.target.checked)}
          disabled={running}
          className="size-4 accent-(--color-secondary)"
        />
        {a.publishNow}
      </label>

      <div className="mt-5 flex flex-wrap items-center gap-2">
        <label
          className={`inline-flex h-10 cursor-pointer items-center gap-2 rounded-full border border-border bg-surface px-5 text-sm font-semibold text-secondary hover:border-primary hover:text-primary-text focus-within:outline-2 focus-within:outline-primary ${
            running || queue.length >= GALLERY_MAX_FILES ? "pointer-events-none opacity-50" : ""
          }`}
        >
          <Icon name="image" className="size-4" />
          {queue.length ? a.chooseMore : a.choose}
          <input
            type="file"
            multiple
            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            onChange={choose}
            disabled={running || queue.length >= GALLERY_MAX_FILES}
            className="sr-only"
          />
        </label>
        {queue.length > 0 && (
          <>
            <Button onClick={() => void start()} disabled={running || sendable.length === 0}>
              <Icon name="upload" className="size-4" />
              {running ? fill(a.uploading, { done: digits(Math.min(run.processed + 1, run.total)), total: digits(run.total) }) : fill(a.upload, { n: digits(sendable.length) })}
            </Button>
            <Button variant="ghost" onClick={clear} disabled={running}>
              {a.clear}
            </Button>
          </>
        )}
      </div>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {queue.length > 0 && (
        <ul className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          {queue.map((q) => (
            <li
              key={q.id}
              className={`flex gap-3 rounded-control border p-2.5 ${
                q.state === "invalid" || q.state === "failed" ? "border-danger/40 bg-danger-soft/40" : "border-border bg-background"
              }`}
            >
              {/* eslint-disable-next-line @next/next/no-img-element -- local preview of a chosen file */}
              <img src={q.preview} alt="" className="size-16 shrink-0 rounded-lg bg-border object-cover" />
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-heading" title={q.file.name}>
                  {q.file.name}
                </p>
                <p className="text-xs text-muted">{formatSize(q.file.size, locale)}</p>
                <p
                  className={`mt-0.5 text-xs font-medium ${
                    q.state === "invalid" || q.state === "failed" ? "text-danger" : q.state === "done" ? "text-success" : "text-secondary"
                  }`}
                >
                  {statusText(q)}
                </p>
                {q.state === "sending" && (
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-border" role="progressbar" aria-valuenow={q.progress} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full bg-brand transition-[width]" style={{ width: `${q.progress}%` }} />
                  </div>
                )}
              </div>
              {!running && q.state !== "done" && (
                <button
                  type="button"
                  onClick={() => remove(q)}
                  aria-label={`${a.remove}: ${q.file.name}`}
                  title={a.remove}
                  className="flex size-8 shrink-0 items-center justify-center rounded-full text-muted hover:bg-danger-soft hover:text-danger"
                >
                  <Icon name="close" className="size-4" />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
