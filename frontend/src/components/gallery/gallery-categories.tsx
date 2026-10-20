"use client";

import { useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { apiFetch, type GalleryCategory } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { CATEGORY_KEY, categoryName } from "./gallery-shared";

type Fields = { key: string; name_bn: string; name_en: string };
type Errors = Partial<Record<keyof Fields, string>>;
type Flash = { tone: "success" | "danger"; text: string } | null;

/** Gallery categories (public tabs): add, rename, delete (the API refuses while photos remain). */
export function GalleryCategories({
  categories,
  failed,
  onChanged,
}: {
  categories: GalleryCategory[] | null;
  failed: boolean;
  onChanged: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.gallery.admin;
  const digits = (n: number) => localizeDigits(String(n), locale);
  const required = t.auth.errors.required;
  const tooLong = (max: number) => fill(t.auth.errors.tooLong, { max: digits(max) });

  const [adding, setAdding] = useState<Fields | null>(null);
  const [renaming, setRenaming] = useState<{ category: GalleryCategory; fields: Fields } | null>(null);
  const [deleting, setDeleting] = useState<GalleryCategory | null>(null);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);

  function check(f: Fields, withKey: boolean): Errors {
    const found: Errors = {};
    if (withKey) {
      if (!f.key.trim()) found.key = required;
      else if (f.key.trim().length > 50) found.key = tooLong(50);
      else if (!CATEGORY_KEY.test(f.key.trim())) found.key = a.keyError;
    }
    for (const field of ["name_bn", "name_en"] as const) {
      if (!f[field].trim()) found[field] = required;
      else if (f[field].trim().length > 100) found[field] = tooLong(100);
    }
    return found;
  }

  function reset() {
    setErrors({});
    setFormError(null);
    setFlash(null);
  }

  function serverErrors(res: Parameters<typeof fieldErrors>[0]) {
    const server = fieldErrors(res);
    setErrors({ key: server.key, name_bn: server.name_bn, name_en: server.name_en });
    setFormError(Object.keys(server).length ? t.auth.fixErrors : failureText(res, t));
  }

  async function add(e: React.FormEvent) {
    e.preventDefault();
    if (!adding) return;
    const found = check(adding, true);
    setErrors(found);
    setFormError(null);
    if (Object.keys(found).length) return;
    setBusy(true);
    const res = await apiFetch<{ message: string }>("/admin/gallery/categories", {
      method: "POST",
      body: { key: adding.key.trim(), name_bn: adding.name_bn.trim(), name_en: adding.name_en.trim() },
    });
    setBusy(false);
    if (res.ok) {
      setAdding(null);
      setFlash({ tone: "success", text: res.data.message });
      onChanged();
    } else serverErrors(res);
  }

  async function rename(e: React.FormEvent) {
    e.preventDefault();
    if (!renaming) return;
    const { category, fields } = renaming;
    const found = check(fields, false);
    setErrors(found);
    setFormError(null);
    if (Object.keys(found).length) return;
    const body: Partial<Fields> = {};
    if (fields.name_bn.trim() !== category.name_bn) body.name_bn = fields.name_bn.trim();
    if (fields.name_en.trim() !== category.name_en) body.name_en = fields.name_en.trim();
    if (Object.keys(body).length === 0) {
      setRenaming(null);
      setFlash({ tone: "success", text: a.nothingChanged });
      return;
    }
    setBusy(true);
    const res = await apiFetch<{ message: string }>(`/admin/gallery/categories/${category.id}`, { method: "PATCH", body });
    setBusy(false);
    if (res.ok) {
      setRenaming(null);
      setFlash({ tone: "success", text: res.data.message });
      onChanged();
    } else serverErrors(res);
  }

  async function remove(category: GalleryCategory) {
    setBusy(true);
    setFormError(null);
    const res = await apiFetch<{ message: string }>(`/admin/gallery/categories/${category.id}`, { method: "DELETE" });
    setBusy(false);
    if (res.ok) {
      setDeleting(null);
      setFlash({ tone: "success", text: res.data.message });
      onChanged();
    } else setFormError(failureText(res, t)); // e.g. "This category still has photos…" (bn/en from the API)
  }

  const nameFields = (f: Fields, set: (patch: Partial<Fields>) => void) => (
    <>
      <Input label={a.nameBn} value={f.name_bn} onChange={(e) => set({ name_bn: e.target.value })} error={errors.name_bn} maxLength={100} />
      <Input label={a.nameEn} value={f.name_en} onChange={(e) => set({ name_en: e.target.value })} error={errors.name_en} maxLength={100} />
    </>
  );
  const clearErrors = (patch: Partial<Fields>) =>
    setErrors((er) => ({ ...er, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">{a.categoriesTitle}</h2>
          <p className="mt-1 max-w-2xl text-sm text-muted">{a.categoriesIntro}</p>
        </div>
        {!adding && (
          <Button
            variant="outline"
            disabled={busy}
            onClick={() => {
              reset();
              setAdding({ key: "", name_bn: "", name_en: "" });
            }}
          >
            <Icon name="plus" className="size-4" />
            {a.addCategory}
          </Button>
        )}
      </div>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {adding && (
        <form onSubmit={add} noValidate className="mt-5 rounded-card border border-border bg-background p-4 sm:p-5">
          <p className="font-semibold text-heading">{a.addCategory}</p>
          <div className="mt-4 grid gap-4 md:grid-cols-3">
            <div>
              <Input
                label={a.key}
                value={adding.key}
                onChange={(e) => {
                  setAdding({ ...adding, key: e.target.value.toLowerCase() });
                  clearErrors({ key: "" });
                }}
                error={errors.key}
                maxLength={50}
                autoCapitalize="none"
                autoCorrect="off"
                spellCheck={false}
                lang="en"
              />
              <p className="mt-1 text-xs text-muted">{a.keyHint}</p>
            </div>
            {nameFields(adding, (patch) => {
              setAdding({ ...adding, ...patch });
              clearErrors(patch);
            })}
          </div>
          {formError && (
            <Alert tone="danger" className="mt-4">
              {formError}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap gap-2">
            <Button type="submit" disabled={busy}>
              {busy ? a.saving : a.save}
            </Button>
            <Button variant="ghost" disabled={busy} onClick={() => setAdding(null)}>
              {a.cancel}
            </Button>
          </div>
        </form>
      )}

      {failed ? (
        <p className="mt-5 text-danger">{a.loadFailed}</p>
      ) : !categories ? (
        <p role="status" className="mt-5 text-muted">
          {t.gallery.loading}
        </p>
      ) : (
        <ul className="mt-5 divide-y divide-border rounded-card border border-border">
          {categories.map((c) => (
            <li key={c.id} className="flex flex-wrap items-center gap-3 px-4 py-3">
              <div className="min-w-0 flex-1">
                <p className="font-semibold break-words text-heading">
                  {categoryName(c, locale)}
                  <span className="ml-2 text-sm font-normal text-muted">{locale === "bn" ? c.name_en : c.name_bn}</span>
                </p>
                <p className="text-xs text-muted">
                  <span lang="en" className="font-mono">
                    {c.key}
                  </span>
                  {" · "}
                  {fill(a.photosCount, { n: digits(c.images_count) })}
                </p>
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  onClick={() => {
                    reset();
                    setRenaming({ category: c, fields: { key: c.key, name_bn: c.name_bn, name_en: c.name_en } });
                  }}
                >
                  <Icon name="pencil" className="size-4" />
                  {a.rename}
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  disabled={busy}
                  onClick={() => {
                    reset();
                    setDeleting(c);
                  }}
                >
                  <Icon name="trash" className="size-4" />
                  {a.delete}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {renaming && (
        <Modal title={a.renameTitle} onClose={() => !busy && setRenaming(null)}>
          <form onSubmit={rename} noValidate className="space-y-4">
            <p className="text-sm text-muted">
              {a.key}:{" "}
              <span lang="en" className="font-mono text-heading">
                {renaming.category.key}
              </span>
            </p>
            {nameFields(renaming.fields, (patch) => {
              setRenaming({ ...renaming, fields: { ...renaming.fields, ...patch } });
              clearErrors(patch);
            })}
            {formError && <Alert tone="danger">{formError}</Alert>}
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="ghost" disabled={busy} onClick={() => setRenaming(null)}>
                {a.cancel}
              </Button>
              <Button type="submit" disabled={busy}>
                {busy ? a.saving : a.save}
              </Button>
            </div>
          </form>
        </Modal>
      )}

      {deleting && (
        <Modal title={a.deleteCategoryTitle} onClose={() => !busy && setDeleting(null)}>
          <p>{fill(a.deleteCategoryText, { name: categoryName(deleting, locale) })}</p>
          {formError && (
            <Alert tone="danger" className="mt-4">
              {formError}
            </Alert>
          )}
          <div className="mt-5 flex flex-wrap justify-end gap-2">
            <Button variant="ghost" disabled={busy} onClick={() => setDeleting(null)}>
              {a.cancel}
            </Button>
            <Button variant="danger" disabled={busy} onClick={() => void remove(deleting)}>
              {busy ? a.saving : a.delete}
            </Button>
          </div>
        </Modal>
      )}
    </Card>
  );
}
