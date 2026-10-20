"use client";

import Image from "next/image";
import { useEffect, useMemo, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { checkFile, failureText, fieldErrors, formatSize } from "@/components/profile/profile-shared";
import { apiFetch, type AdminHeroSlide, type ApiResult } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import {
  HERO_BUTTON_MAX,
  HERO_IMAGE_MAX_BYTES,
  HERO_IMAGE_TYPES,
  HERO_LINK_MAX,
  HERO_SUBTITLE_MAX,
  HERO_TITLE_MAX,
  isAllowedHeroLink,
} from "./hero-slides-shared";

const TEXT_FIELDS = ["title_bn", "title_en", "subtitle_bn", "subtitle_en", "button_text_bn", "button_text_en", "button_link"] as const;
type TextField = (typeof TEXT_FIELDS)[number];
type Draft = Record<TextField, string> & {
  /** new slides only — existing slides use the list's switch */
  is_active: boolean;
};
type Field = TextField | "image";
type Saved = { message: string; data: AdminHeroSlide };

const MAX: Record<TextField, number> = {
  title_bn: HERO_TITLE_MAX,
  title_en: HERO_TITLE_MAX,
  subtitle_bn: HERO_SUBTITLE_MAX,
  subtitle_en: HERO_SUBTITLE_MAX,
  button_text_bn: HERO_BUTTON_MAX,
  button_text_en: HERO_BUTTON_MAX,
  button_link: HERO_LINK_MAX,
};

function draftFrom(slide: AdminHeroSlide | null): Draft {
  return {
    title_bn: slide?.title_bn ?? "",
    title_en: slide?.title_en ?? "",
    subtitle_bn: slide?.subtitle_bn ?? "",
    subtitle_en: slide?.subtitle_en ?? "",
    button_text_bn: slide?.button_text_bn ?? "",
    button_text_en: slide?.button_text_en ?? "",
    button_link: slide?.button_link ?? "",
    is_active: slide?.is_active ?? true,
  };
}

/**
 * Add / edit a Home hero slide (admin): picture (required, JPG/PNG/WebP ≤ 2 MB, checked here first),
 * optional bn/en title, subtitle and button text, button link (/page or https://). One request:
 * POST for a new slide, PATCH (multipart when a new picture is chosen) for an existing one.
 */
export function HeroSlideForm({
  slide,
  onDone,
  onCancel,
}: {
  /** null = new slide */
  slide: AdminHeroSlide | null;
  /** Saved: back to the list with this message. */
  onDone: (message: string) => void;
  onCancel: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.heroAdmin;
  const [draft, setDraft] = useState<Draft>(() => draftFrom(slide));
  const [file, setFile] = useState<File | null>(null);
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

  const set = (patch: Partial<Draft>) => {
    setDraft((d) => ({ ...d, ...patch }));
    setErrors((e) => ({ ...e, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));
  };

  const crop = useImageCrop();

  function chooseFile(chosen: File | undefined) {
    if (fileInput.current) fileInput.current.value = "";
    if (!chosen) return;
    const problem = checkFile(chosen, HERO_IMAGE_TYPES, HERO_IMAGE_MAX_BYTES, {
      badType: a.badType,
      tooBig: fill(a.tooBig, { size: formatSize(chosen.size, locale) }),
    });
    if (problem) return setErrors((e) => ({ ...e, image: problem }));
    setErrors((e) => ({ ...e, image: undefined }));
    setFile(chosen);
  }

  function validate(): boolean {
    const found: Partial<Record<Field, string>> = {};
    for (const key of TEXT_FIELDS) {
      if (draft[key].trim().length > MAX[key]) found[key] = fill(a.tooLong, { max: digits(MAX[key]) });
    }
    const link = draft.button_link.trim();
    const hasText = !!(draft.button_text_bn.trim() || draft.button_text_en.trim());
    if (!found.button_link) {
      if (link && !isAllowedHeroLink(link)) found.button_link = a.linkInvalid;
      else if (hasText && !link) found.button_link = a.buttonNeedsLink;
      else if (link && !hasText) found.button_link = a.linkNeedsText;
    }
    if (!slide && !file) found.image = a.imageRequired;
    if (errors.image) found.image = errors.image;
    setErrors(found);
    return Object.keys(found).length === 0;
  }

  /** 422 → messages under the fields; anything else (network, 403 …) → box. */
  function handle(res: ApiResult<Saved>): boolean {
    if (res.ok) return true;
    const fe = fieldErrors(res);
    const known: Field[] = [...TEXT_FIELDS, "image"];
    const mine = Object.fromEntries(known.filter((k) => fe[k]).map((k) => [k, fe[k]]));
    setErrors((e) => ({ ...e, ...mine }));
    // A file too big for the web server itself never reaches Laravel (413) → say it plainly under the picture.
    if (res.status === 413) setErrors((e) => ({ ...e, image: fill(a.tooBig, { size: file ? formatSize(file.size, locale) : "" }) }));
    setFormError(Object.keys(mine).length ? t.auth.fixErrors : failureText(res, t));
    return false;
  }

  async function submit() {
    setFormError(null);
    if (!validate()) {
      setFormError(t.auth.fixErrors);
      return;
    }

    const original = draftFrom(slide);
    const changed = TEXT_FIELDS.filter((key) => draft[key].trim() !== original[key].trim());
    if (slide && !file && changed.length === 0) {
      onDone(a.nothingChanged);
      return;
    }

    // Empty texts are sent as "" — Laravel turns them into null (removes that text).
    const body = new FormData();
    for (const key of slide ? changed : TEXT_FIELDS) body.append(key, draft[key].trim());
    if (file) body.append("image", file);
    if (slide) body.append("_method", "PATCH");
    else body.append("is_active", draft.is_active ? "1" : "0");

    setBusy(true);
    const res = await apiFetch<Saved>(slide ? `/admin/hero-slides/${slide.id}` : "/admin/hero-slides", { method: "POST", body });
    setBusy(false);
    if (handle(res) && res.ok) onDone(res.data.message);
  }

  const previewImage = filePreview ?? slide?.image_url ?? null;
  const previewTitle = (locale === "en" ? draft.title_en || draft.title_bn : draft.title_bn || draft.title_en).trim();
  const previewSubtitle = (locale === "en" ? draft.subtitle_en || draft.subtitle_bn : draft.subtitle_bn || draft.subtitle_en).trim();
  const previewButton = (locale === "en" ? draft.button_text_en || draft.button_text_bn : draft.button_text_bn || draft.button_text_en).trim();

  const text = (key: TextField, label: string) => (
    <Input label={label} value={draft[key]} onChange={(e) => set({ [key]: e.target.value })} error={errors[key]} maxLength={MAX[key]} />
  );

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">{slide ? a.editTitle : a.createTitle}</h2>
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
        <div className="min-w-0 lg:col-span-2">
          <p className="mb-1.5 block text-sm font-medium text-foreground">
            {a.imageField} <span className="text-danger">*</span>
          </p>
          <p className="mb-3 text-sm text-muted">{a.imageHint}</p>
          <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
            <div className="w-full sm:max-w-sm">
              <p className="mb-1.5 text-xs font-semibold text-muted">{a.previewLabel}</p>
              {/* Same box shape and caption style as the Home hero slider */}
              <div className="relative isolate aspect-[4/3] w-full overflow-hidden rounded-card bg-navy-card text-white">
                {previewImage ? (
                  <Image src={previewImage} alt="" fill unoptimized sizes="384px" className="-z-10 object-cover" />
                ) : (
                  <div className="flex h-full flex-col items-center justify-center gap-2 text-white/60">
                    <Icon name="image" className="size-8" />
                    <span className="text-sm">{a.noImage}</span>
                  </div>
                )}
                {previewImage && (previewTitle || previewSubtitle || previewButton) && (
                  <>
                    <div className="absolute inset-0 -z-10 bg-linear-to-t from-navy/90 via-navy/30 to-transparent" />
                    <div className="flex h-full flex-col items-start justify-end p-5">
                      {previewTitle && <p className="line-clamp-2 font-heading text-base font-semibold drop-shadow">{previewTitle}</p>}
                      {previewSubtitle && <p className="mt-1 line-clamp-2 text-sm text-white/85 drop-shadow">{previewSubtitle}</p>}
                      {previewButton && draft.button_link.trim() && (
                        <span className="mt-3 inline-flex rounded-control bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                          {previewButton}
                        </span>
                      )}
                    </div>
                  </>
                )}
              </div>
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
              <input
                ref={fileInput}
                type="file"
                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                className="sr-only"
                id="hero-slide-image"
                onChange={(e) => {
              crop.pick(e.target.files?.[0], "heroSlide", chooseFile);
              e.target.value = "";
            }}
              />
          {crop.dialog}
              <Button variant="outline" size="sm" disabled={busy} onClick={() => fileInput.current?.click()}>
                <Icon name="upload" className="size-4" />
                {slide || file ? a.replaceImage : a.chooseImage}
              </Button>
              {errors.image && (
                <Alert tone="danger" className="text-sm">
                  {errors.image}
                </Alert>
              )}
            </div>
          </div>
        </div>

        {text("title_bn", a.titleBn)}
        {text("title_en", a.titleEn)}
        {text("subtitle_bn", a.subtitleBn)}
        {text("subtitle_en", a.subtitleEn)}
        {text("button_text_bn", a.buttonTextBn)}
        {text("button_text_en", a.buttonTextEn)}
        <div className="min-w-0 lg:col-span-2">
          <Input
            label={a.buttonLink}
            value={draft.button_link}
            onChange={(e) => set({ button_link: e.target.value })}
            error={errors.button_link}
            maxLength={HERO_LINK_MAX}
            placeholder="/register"
            inputMode="url"
            autoCapitalize="none"
            spellCheck={false}
          />
          <p className="mt-1.5 text-sm text-muted">{a.buttonLinkHint}</p>
        </div>
        <p className="-mt-2 text-sm text-muted lg:col-span-2">{a.englishNote}</p>

        {!slide && (
          <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-medium text-heading lg:col-span-2">
            <input
              type="checkbox"
              checked={draft.is_active}
              onChange={(e) => set({ is_active: e.target.checked })}
              className="size-4 accent-(--color-secondary)"
            />
            {a.activeField}
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
