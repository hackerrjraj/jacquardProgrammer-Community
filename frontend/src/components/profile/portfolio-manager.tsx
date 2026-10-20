"use client";

import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { useImageCrop } from "@/components/ui/image-crop-upload";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { apiFetch, type PortfolioItem, type Profile, type ProfileOptions } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { PortfolioCard } from "./profile-view";
import { PHOTO_MAX_BYTES, PHOTO_TYPES, PORTFOLIO_MAX, checkFile, failureText, fieldErrors } from "./profile-shared";

type Draft = { category: string; title: string; description: string };
type DraftErrors = Partial<Record<"image" | "category" | "title" | "description", string>>;
type Editing = { item: PortfolioItem | null; draft: Draft } | null; // item null = new
type Flash = { tone: "success" | "danger"; text: string } | null;

/** Portfolio (max 12): add / edit / delete / reorder (up-down buttons, or drag on desktop). */
export function PortfolioManager({
  profile,
  options,
  onSaved,
}: {
  profile: Profile;
  options: ProfileOptions | null;
  onSaved: (p: Profile) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const pf = t.profile.portfolio;
  const items = profile.portfolio;
  const digits = (n: number) => localizeDigits(String(n), locale);
  const [editing, setEditing] = useState<Editing>(null);
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [errors, setErrors] = useState<DraftErrors>({});
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<PortfolioItem | null>(null);
  const dragFrom = useRef<number | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!preview) return;
    return () => URL.revokeObjectURL(preview);
  }, [preview]);

  const setItems = (next: PortfolioItem[]) => onSaved({ ...profile, portfolio: next });

  function open(item: PortfolioItem | null) {
    setFlash(null);
    setErrors({});
    setFile(null);
    setPreview(null);
    setEditing({
      item,
      draft: item
        ? { category: item.category, title: item.title, description: item.description ?? "" }
        : { category: "", title: "", description: "" },
    });
  }

  const crop = useImageCrop();

  function choose(e: React.ChangeEvent<HTMLInputElement>) {
    setErrors((er) => ({ ...er, image: undefined }));
    crop.pick(e.target.files?.[0], "portfolio", accept);
    e.target.value = "";
  }

  function accept(chosen: File) {
    const problem = checkFile(chosen, PHOTO_TYPES, PHOTO_MAX_BYTES, t.profile.photo);
    if (problem) {
      setFile(null);
      setPreview(null);
      setErrors((er) => ({ ...er, image: problem }));
      return;
    }
    setFile(chosen);
    setPreview(URL.createObjectURL(chosen));
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    const d = editing.draft;
    const found: DraftErrors = {};
    if (!editing.item && !file) found.image = t.auth.errors.required;
    if (!d.category) found.category = t.auth.errors.required;
    if (!d.title.trim()) found.title = t.auth.errors.required;
    else if (d.title.trim().length > 100) found.title = t.auth.errors.tooLong.replace("{max}", digits(100));
    if (d.description.trim().length > 300) found.description = t.auth.errors.tooLong.replace("{max}", digits(300));
    setErrors(found);
    if (Object.keys(found).length) return;

    setBusy(true);
    setFlash(null);
    const fields = { category: d.category, title: d.title.trim(), description: d.description.trim() };
    let res;
    if (file) {
      // New image → multipart; an edit uses POST + _method=PUT (PHP can't read files on PUT).
      const body = new FormData();
      body.append("image", file);
      for (const [k, v] of Object.entries(fields)) body.append(k, v);
      if (editing.item) body.append("_method", "PUT");
      res = await apiFetch<{ message: string; data: PortfolioItem }>(
        editing.item ? `/profile/portfolio/${editing.item.id}` : "/profile/portfolio",
        { method: "POST", body },
      );
    } else {
      res = await apiFetch<{ message: string; data: PortfolioItem }>(`/profile/portfolio/${editing.item!.id}`, {
        method: "PUT",
        body: fields,
      });
    }
    setBusy(false);

    if (res.ok) {
      const saved = res.data.data;
      setItems(editing.item ? items.map((i) => (i.id === saved.id ? saved : i)) : [...items, saved]);
      setEditing(null);
      setFile(null);
      setPreview(null);
      setFlash({ tone: "success", text: res.data.message });
      return;
    }
    const server = fieldErrors(res);
    setErrors({ image: server.image, category: server.category, title: server.title, description: server.description });
    setFlash({ tone: "danger", text: Object.keys(server).length ? t.auth.fixErrors : failureText(res, t) });
  }

  async function saveOrder(next: PortfolioItem[]) {
    const previous = items;
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ data: PortfolioItem[] }>("/profile/portfolio/order", {
      method: "PUT",
      body: { ids: next.map((i) => i.id) },
    });
    setBusy(false);
    if (res.ok) setItems(res.data.data);
    else {
      setItems(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
  }

  function move(from: number, to: number) {
    if (to < 0 || to >= items.length || from === to) return;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    void saveOrder(next);
  }

  async function remove(item: PortfolioItem) {
    setBusy(true);
    const res = await apiFetch<{ message: string }>(`/profile/portfolio/${item.id}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) {
      setItems(items.filter((i) => i.id !== item.id));
      setDeleting(null);
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setFlash({ tone: "danger", text: failureText(res, t) });
    }
  }

  const setDraft = (patch: Partial<Draft>) => {
    setEditing((cur) => (cur ? { ...cur, draft: { ...cur.draft, ...patch } } : cur));
    setErrors((er) => ({ ...er, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));
  };

  return (
    <Card className="p-5 sm:p-6">
      {crop.dialog}
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h3 className="text-lg font-semibold">{pf.title}</h3>
          <p className="mt-1 text-sm text-muted">{fill(pf.intro, { max: digits(PORTFOLIO_MAX) })}</p>
        </div>
        {!editing && (
          <Button variant="outline" disabled={items.length >= PORTFOLIO_MAX || busy} onClick={() => open(null)}>
            <Icon name="plus" className="size-4" />
            {pf.add}
          </Button>
        )}
      </div>
      {items.length >= PORTFOLIO_MAX && !editing && (
        <p className="mt-3 text-sm text-muted">{fill(pf.max, { max: digits(PORTFOLIO_MAX) })}</p>
      )}

      {flash && !(editing && flash.tone === "danger") && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {editing && (
        <form onSubmit={submit} noValidate className="mt-5 rounded-card border border-border bg-background p-4 sm:p-5">
          <p className="font-semibold text-heading">{editing.item ? pf.edit : pf.add}</p>
          <div className="mt-4 grid gap-4 sm:grid-cols-[10rem_1fr]">
            <div>
              <span className="relative block aspect-[4/3] w-full overflow-hidden rounded-xl bg-surface sm:w-40">
                {preview || editing.item ? (
                  // eslint-disable-next-line @next/next/no-img-element -- local preview or API image
                  <img src={preview ?? editing.item!.image_url} alt="" className="size-full object-cover" />
                ) : (
                  <span className="flex size-full items-center justify-center text-muted">
                    <Icon name="upload" className="size-8" />
                  </span>
                )}
              </span>
            </div>
            <div className="min-w-0">
              <label className="inline-flex h-10 cursor-pointer items-center rounded-full border border-border bg-surface px-5 text-sm font-semibold text-secondary hover:border-primary hover:text-primary-text focus-within:outline-2 focus-within:outline-primary">
                {editing.item || file ? pf.changeImage : pf.chooseImage}
                <input ref={fileRef} type="file" accept=".jpg,.jpeg,.png,.webp" onChange={choose} className="sr-only" />
              </label>
              <p className="mt-2 text-sm text-muted">
                {t.profile.photo.hint}
                {editing.item && ` ${pf.keepImage}`}
              </p>
              {errors.image && <p className="mt-1 text-sm text-danger">{errors.image}</p>}
            </div>
          </div>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <Select label={pf.category} value={editing.draft.category} onChange={(e) => setDraft({ category: e.target.value })} error={errors.category}>
              <option value="">{t.profile.basic.choose}</option>
              {options?.portfolio_categories.map((o) => (
                <option key={o.key} value={o.key}>
                  {locale === "bn" ? o.name_bn : o.name_en}
                </option>
              ))}
            </Select>
            <Input label={pf.itemTitle} value={editing.draft.title} onChange={(e) => setDraft({ title: e.target.value })} error={errors.title} maxLength={100} />
            <div className="sm:col-span-2">
              <Textarea
                label={pf.description}
                value={editing.draft.description}
                onChange={(e) => setDraft({ description: e.target.value })}
                error={errors.description}
                maxLength={300}
                rows={3}
                className="[&_textarea]:min-h-24"
              />
            </div>
          </div>
          {flash?.tone === "danger" && (
            <Alert tone="danger" className="mt-4">
              {flash.text}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap gap-2">
            <Button type="submit" disabled={busy}>
              {busy ? t.profile.saving : t.profile.save}
            </Button>
            <Button
              variant="ghost"
              disabled={busy}
              onClick={() => {
                setEditing(null);
                setFlash(null);
              }}
            >
              {t.profile.cancel}
            </Button>
          </div>
        </form>
      )}

      {items.length === 0 ? (
        !editing && <p className="mt-4 text-muted">{t.profile.noPortfolio}</p>
      ) : (
        <>
          {items.length > 1 && <p className="mt-5 hidden text-sm text-muted lg:block">{pf.dragHint}</p>}
          <ul className="mt-3 grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
            {items.map((item, i) => (
              <li
                key={item.id}
                draggable={!busy && !editing}
                onDragStart={() => {
                  dragFrom.current = i;
                }}
                onDragOver={(e) => e.preventDefault()}
                onDrop={(e) => {
                  e.preventDefault();
                  if (dragFrom.current !== null) move(dragFrom.current, i);
                  dragFrom.current = null;
                }}
                className="cursor-grab active:cursor-grabbing"
              >
                <PortfolioCard item={item} options={options}>
                  <div className="mt-auto flex flex-wrap items-center gap-2 pt-4">
                    <Button
                      variant="outline"
                      size="sm"
                      aria-label={pf.moveUp}
                      title={pf.moveUp}
                      disabled={busy || !!editing || i === 0}
                      onClick={() => move(i, i - 1)}
                      className="px-3"
                    >
                      <Icon name="arrowUp" className="size-4" />
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      aria-label={pf.moveDown}
                      title={pf.moveDown}
                      disabled={busy || !!editing || i === items.length - 1}
                      onClick={() => move(i, i + 1)}
                      className="px-3"
                    >
                      <Icon name="arrowDown" className="size-4" />
                    </Button>
                    <Button variant="outline" size="sm" disabled={busy || !!editing} onClick={() => open(item)}>
                      <Icon name="pencil" className="size-4" />
                      {pf.edit}
                    </Button>
                    <Button variant="danger" size="sm" disabled={busy || !!editing} onClick={() => setDeleting(item)}>
                      <Icon name="trash" className="size-4" />
                      {t.profile.delete}
                    </Button>
                  </div>
                </PortfolioCard>
              </li>
            ))}
          </ul>
        </>
      )}

      {deleting && (
        <Modal title={pf.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <p>{fill(pf.deleteText, { title: deleting.title })}</p>
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {t.profile.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? t.profile.saving : t.profile.delete}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}
