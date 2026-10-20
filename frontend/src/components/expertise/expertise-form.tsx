"use client";

import Image from "next/image";
import { useEffect, useMemo, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { checkFile, failureText, fieldErrors, formatSize } from "@/components/profile/profile-shared";
import { apiFetch, type AdminExpertiseCard, type ApiResult } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import {
  EXPERTISE_ICONS,
  EXPERTISE_IMAGE_MAX_BYTES,
  EXPERTISE_IMAGE_TYPES,
  EXPERTISE_TEXT_MAX,
  EXPERTISE_TITLE_MAX,
  ExpertisePreview,
  homeIcon,
} from "./expertise-shared";

type Draft = {
  title_bn: string;
  title_en: string;
  description_bn: string;
  description_en: string;
  icon: IconName;
  /** new cards only — existing cards use the list's Show / Hide button */
  is_published: boolean;
};
type Field = Exclude<keyof Draft, "is_published"> | "image";
type Saved = { message: string; data: AdminExpertiseCard };

function draftFrom(card: AdminExpertiseCard | null): Draft {
  return {
    title_bn: card?.title_bn ?? "",
    title_en: card?.title_en ?? "",
    description_bn: card?.description_bn ?? "",
    description_en: card?.description_en ?? "",
    icon: homeIcon(card?.icon),
    is_published: card?.is_published ?? true,
  };
}

/**
 * Add / edit an Expertise card (admin). bn/en title + text, icon (shown when there is no picture),
 * optional picture (JPG/PNG/WebP ≤ 2 MB, checked here first). Editing uses the API's separate routes in order:
 * texts/icon (PATCH, only what changed) → picture (upload or remove).
 */
export function ExpertiseForm({
  card,
  onDone,
  onCancel,
}: {
  /** null = new card */
  card: AdminExpertiseCard | null;
  /** Saved: back to the list with this message. */
  onDone: (message: string) => void;
  onCancel: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.expertiseAdmin;
  const [current, setCurrent] = useState<AdminExpertiseCard | null>(card);
  const [draft, setDraft] = useState<Draft>(() => draftFrom(card));
  const [file, setFile] = useState<File | null>(null);
  const [removeImage, setRemoveImage] = useState(false);
  const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  const digits = (v: number) => localizeDigits(String(v), locale);

  // Preview of the newly chosen picture (freed when it changes / the form closes).
  const filePreview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file]);
  useEffect(() => () => {
    if (filePreview) URL.revokeObjectURL(filePreview);
  }, [filePreview]);

  // An existing card with an icon outside the offered list keeps it selectable.
  const icons: IconName[] = (EXPERTISE_ICONS as readonly IconName[]).includes(draftFrom(card).icon)
    ? [...EXPERTISE_ICONS]
    : [draftFrom(card).icon, ...EXPERTISE_ICONS];

  const set = (patch: Partial<Draft>) => {
    setDraft((d) => ({ ...d, ...patch }));
    setErrors((e) => ({ ...e, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));
  };

  const crop = useImageCrop();

  function chooseFile(chosen: File | undefined) {
    if (fileInput.current) fileInput.current.value = "";
    if (!chosen) return;
    const problem = checkFile(chosen, EXPERTISE_IMAGE_TYPES, EXPERTISE_IMAGE_MAX_BYTES, {
      badType: a.badType,
      tooBig: fill(a.tooBig, { size: formatSize(chosen.size, locale) }),
    });
    if (problem) return setErrors((e) => ({ ...e, image: problem }));
    setErrors((e) => ({ ...e, image: undefined }));
    setFile(chosen);
    setRemoveImage(false);
  }

  function validate(): boolean {
    const found: Partial<Record<Field, string>> = {};
    const tooLong = (max: number) => fill(a.tooLong, { max: digits(max) });
    if (!draft.title_bn.trim()) found.title_bn = a.required;
    else if (draft.title_bn.trim().length > EXPERTISE_TITLE_MAX) found.title_bn = tooLong(EXPERTISE_TITLE_MAX);
    if (draft.title_en.trim().length > EXPERTISE_TITLE_MAX) found.title_en = tooLong(EXPERTISE_TITLE_MAX);
    if (!draft.description_bn.trim()) found.description_bn = a.required;
    else if (draft.description_bn.trim().length > EXPERTISE_TEXT_MAX) found.description_bn = tooLong(EXPERTISE_TEXT_MAX);
    if (draft.description_en.trim().length > EXPERTISE_TEXT_MAX) found.description_en = tooLong(EXPERTISE_TEXT_MAX);
    if (errors.image) found.image = errors.image;
    setErrors(found);
    return Object.keys(found).length === 0;
  }

  /** 422 → messages under the fields; anything else (e.g. too many cards, network) → box. */
  function handle(res: ApiResult<unknown>): boolean {
    if (res.ok) return true;
    const fe = fieldErrors(res);
    const known = ["title_bn", "title_en", "description_bn", "description_en", "icon", "image"] as const;
    const mine = Object.fromEntries(known.filter((k) => fe[k]).map((k) => [k, fe[k]]));
    setErrors((e) => ({ ...e, ...mine }));
    // A file too big for the web server itself never reaches Laravel (413) → say it plainly under the picture.
    if (res.status === 413) setErrors((e) => ({ ...e, image: fill(a.tooBig, { size: file ? formatSize(file.size, locale) : "" }) }));
    setFormError(Object.keys(mine).length ? t.auth.fixErrors : failureText(res, t));
    return false;
  }

  async function create() {
    const body = new FormData();
    body.append("title_bn", draft.title_bn.trim());
    body.append("title_en", draft.title_en.trim());
    body.append("description_bn", draft.description_bn.trim());
    body.append("description_en", draft.description_en.trim());
    body.append("icon", draft.icon);
    body.append("is_published", draft.is_published ? "1" : "0");
    if (file) body.append("image", file);
    const res = await apiFetch<Saved>("/admin/expertise-cards", { method: "POST", body });
    if (handle(res) && res.ok) onDone(res.data.message);
  }

  async function update(base: AdminExpertiseCard) {
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
      // The texts were saved already — say so, so the admin is not surprised.
      if (stepDone) setFormError(fill(a.partlySaved, { error: failureText(res, t) }));
      return false;
    };

    const original = draftFrom(base);
    const patch: Record<string, string | null> = {};
    for (const key of ["title_bn", "title_en", "description_bn", "description_en"] as const) {
      if (draft[key].trim() !== original[key].trim()) patch[key] = draft[key].trim() || null;
    }
    if (draft.icon !== original.icon) patch.icon = draft.icon;

    if (Object.keys(patch).length && !(await step(await apiFetch<Saved>(`/admin/expertise-cards/${base.id}`, { method: "PATCH", body: patch })))) return;

    if (file) {
      const body = new FormData();
      body.append("image", file);
      if (!(await step(await apiFetch<Saved>(`/admin/expertise-cards/${base.id}/image`, { method: "POST", body })))) return;
      setFile(null);
    } else if (removeImage && latest.image_url) {
      if (!(await step(await apiFetch<Saved>(`/admin/expertise-cards/${base.id}/image`, { method: "DELETE" })))) return;
      setRemoveImage(false);
    }

    onDone(stepDone ? a.saved : a.nothingChanged);
  }

  async function submit() {
    setFormError(null);
    if (!validate()) {
      setFormError(t.auth.fixErrors);
      return;
    }
    setBusy(true);
    if (current) await update(current);
    else await create();
    setBusy(false);
  }

  const existing = current?.image_url ?? null;
  // What the Home card will show after saving.
  const previewImage = filePreview ?? (removeImage ? null : existing);

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
          e.preventDefault();
          void submit();
        }}
      >
        <Input label={a.titleBn} value={draft.title_bn} onChange={(e) => set({ title_bn: e.target.value })} error={errors.title_bn} maxLength={EXPERTISE_TITLE_MAX} required />
        <Input label={a.titleEn} value={draft.title_en} onChange={(e) => set({ title_en: e.target.value })} error={errors.title_en} maxLength={EXPERTISE_TITLE_MAX} />
        <Textarea
          label={a.descriptionBn}
          value={draft.description_bn}
          onChange={(e) => set({ description_bn: e.target.value })}
          error={errors.description_bn}
          maxLength={EXPERTISE_TEXT_MAX}
          rows={5}
          required
        />
        <Textarea
          label={a.descriptionEn}
          value={draft.description_en}
          onChange={(e) => set({ description_en: e.target.value })}
          error={errors.description_en}
          maxLength={EXPERTISE_TEXT_MAX}
          rows={5}
        />
        <p className="-mt-2 text-sm text-muted lg:col-span-2">{a.englishNote}</p>

        <fieldset className="min-w-0 lg:col-span-2">
          <legend className="mb-1.5 block text-sm font-medium text-foreground">{a.iconField}</legend>
          <p className="mb-3 text-sm text-muted">{a.iconHint}</p>
          <div role="radiogroup" aria-label={a.iconField} className="grid grid-cols-4 gap-2 sm:grid-cols-8">
            {icons.map((name) => {
              const selected = draft.icon === name;
              const label = (a.icons as Record<string, string>)[name] ?? name;
              return (
                <button
                  key={name}
                  type="button"
                  role="radio"
                  aria-checked={selected}
                  aria-label={label}
                  title={label}
                  disabled={busy}
                  onClick={() => set({ icon: name })}
                  className={`flex aspect-square min-w-0 items-center justify-center rounded-control border transition ${
                    selected ? "border-secondary bg-brand text-white shadow-sm" : "border-border bg-background text-heading hover:border-secondary"
                  }`}
                >
                  <Icon name={name} className="size-6" />
                </button>
              );
            })}
          </div>
          {errors.icon && <p className="mt-2 text-sm text-danger">{errors.icon}</p>}
        </fieldset>

        <div className="min-w-0 lg:col-span-2">
          <p className="mb-1.5 block text-sm font-medium text-foreground">{a.imageField}</p>
          <p className="mb-3 text-sm text-muted">{a.imageHint}</p>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div className="w-full max-w-xs">
              <p className="mb-1.5 text-xs font-semibold text-muted">{a.previewLabel}</p>
              {previewImage ? (
                <div className="relative aspect-[4/3] w-full overflow-hidden rounded-card bg-border">
                  <Image src={previewImage} alt="" fill unoptimized sizes="320px" className="object-cover" />
                </div>
              ) : (
                <ExpertisePreview imageUrl={null} icon={draft.icon} className="aspect-[4/3] w-full" />
              )}
            </div>
            <div className="min-w-0 flex-1 space-y-3">
              {file && (
                <div className="flex flex-wrap items-center gap-3 rounded-control border border-primary/40 bg-primary-soft/40 px-4 py-3 text-sm">
                  <Icon name="image" className="size-5 shrink-0 text-primary-text" />
                  <span className="min-w-0 flex-1 break-all">
                    {fill(a.newFile, { name: file.name })} · {formatSize(file.size, locale)}
                  </span>
                  <Button variant="ghost" size="sm" disabled={busy} onClick={() => setFile(null)}>
                    {a.cancel}
                  </Button>
                </div>
              )}
              {removeImage && <p className="text-sm text-danger">{a.imageWillBeRemoved}</p>}
              <input
                ref={fileInput}
                type="file"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                className="sr-only"
                id="expertise-image"
                onChange={(e) => {
              crop.pick(e.target.files?.[0], "expertiseCard", chooseFile);
              e.target.value = "";
            }}
              />
          {crop.dialog}
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" disabled={busy} onClick={() => fileInput.current?.click()}>
                  <Icon name="upload" className="size-4" />
                  {existing || file ? a.replaceImage : a.chooseImage}
                </Button>
                {existing && !file && (
                  <Button variant={removeImage ? "outline" : "danger"} size="sm" disabled={busy} onClick={() => setRemoveImage((v) => !v)}>
                    {removeImage ? a.undoRemove : a.removeImage}
                  </Button>
                )}
              </div>
              {errors.image && (
                <Alert tone="danger" className="text-sm">
                  {errors.image}
                </Alert>
              )}
            </div>
          </div>
        </div>

        {!current && (
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-heading lg:col-span-2">
            <input
              type="checkbox"
              checked={draft.is_published}
              onChange={(e) => set({ is_published: e.target.checked })}
              className="size-4 accent-(--color-secondary)"
            />
            {a.publishField}
          </label>
        )}

        {formError && (
          <Alert tone="danger" className="lg:col-span-2">
            {formError}
          </Alert>
        )}

        <div className="flex flex-wrap justify-end gap-2 border-t border-border pt-5 lg:col-span-2">
          <Button variant="ghost" disabled={busy} onClick={onCancel}>
            {a.cancel}
          </Button>
          <Button type="submit" disabled={busy}>
            {busy ? a.saving : a.save}
          </Button>
        </div>
      </form>
    </Card>
  );
}
