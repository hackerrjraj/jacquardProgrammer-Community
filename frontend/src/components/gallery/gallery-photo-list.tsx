"use client";

import Image from "next/image";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Select } from "@/components/ui/select";
import { apiFetch, type AdminGalleryImage, type GalleryCategory, type Paginated } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { failureText, fieldErrors } from "@/components/profile/profile-shared";
import { categoryName, photoTitle } from "./gallery-shared";

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: string; ok: true; items: AdminGalleryImage[]; page: number; last: number; total: number } | { key: string; ok: false };
type Draft = { title_bn: string; title_en: string; category: string };

/**
 * Admin photo list: thumbnail, title, category, published/hidden badge; edit, publish/hide, delete,
 * reorder (arrows, or drag on a computer). Reordering one page or one filtered category only swaps
 * those photos among their own places (backend rule), so other photos never move.
 */
export function GalleryPhotoList({
  categories,
  reloadSignal,
  onChanged,
}: {
  categories: GalleryCategory[];
  /** Bumped by the parent after an upload → reload the list. */
  reloadSignal: number;
  /** Photo counts changed (delete / category change) → parent reloads the categories. */
  onChanged: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.gallery.admin;
  const digits = (n: number) => localizeDigits(String(n), locale);

  const [category, setCategory] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${category}|${status}|${page}|${reloadKey}|${reloadSignal}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [flash, setFlash] = useState<Flash>(null);
  const [busy, setBusy] = useState(false);
  const [editing, setEditing] = useState<{ item: AdminGalleryImage; draft: Draft } | null>(null);
  const [editErrors, setEditErrors] = useState<Partial<Record<keyof Draft, string>>>({});
  const [editError, setEditError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState<AdminGalleryImage | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const dragFrom = useRef<number | null>(null);

  useEffect(() => {
    let cancelled = false;
    const params = new URLSearchParams({ page: String(page) });
    if (category) params.set("category", category);
    if (status) params.set("status", status);
    void apiFetch<Paginated<AdminGalleryImage>>(`/admin/gallery/images?${params}`).then((res) => {
      if (cancelled) return;
      setLoaded(
        res.ok
          ? { key: requestKey, ok: true, items: res.data.data, page: res.data.meta.current_page, last: res.data.meta.last_page, total: res.data.meta.total }
          : { key: requestKey, ok: false },
      );
    });
    return () => {
      cancelled = true;
    };
  }, [category, status, page, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const items = current?.ok ? current.items : [];

  const setItems = (next: AdminGalleryImage[]) =>
    setLoaded((prev) => (prev?.ok ? { ...prev, items: next } : prev));
  const replace = (saved: AdminGalleryImage) => setItems(items.map((i) => (i.id === saved.id ? saved : i)));

  const filter = (setter: (v: string) => void) => (value: string) => {
    setter(value);
    setPage(1);
    setFlash(null);
  };

  async function saveOrder(next: AdminGalleryImage[]) {
    const previous = items;
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string }>("/admin/gallery/images/order", {
      method: "PUT",
      body: { ids: next.map((i) => i.id) },
    });
    setBusy(false);
    if (res.ok) setFlash({ tone: "success", text: res.data.message });
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

  async function togglePublish(item: AdminGalleryImage) {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminGalleryImage }>(`/admin/gallery/images/${item.id}`, {
      method: "PATCH",
      body: { is_published: !item.is_published },
    });
    setBusy(false);
    if (res.ok) {
      replace(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
      onChanged(); // public counts change
    } else setFlash({ tone: "danger", text: failureText(res, t) });
  }

  function openEdit(item: AdminGalleryImage) {
    setFlash(null);
    setEditErrors({});
    setEditError(null);
    setEditing({ item, draft: { title_bn: item.title_bn ?? "", title_en: item.title_en ?? "", category: item.category.key } });
  }

  async function saveEdit(e: React.FormEvent) {
    e.preventDefault();
    if (!editing) return;
    const { item, draft } = editing;
    // Send only what changed.
    const body: Record<string, string | null> = {};
    if (draft.title_bn.trim() !== (item.title_bn ?? "")) body.title_bn = draft.title_bn.trim() || null;
    if (draft.title_en.trim() !== (item.title_en ?? "")) body.title_en = draft.title_en.trim() || null;
    if (draft.category !== item.category.key) body.category = draft.category;
    if (Object.keys(body).length === 0) {
      setEditing(null);
      setFlash({ tone: "success", text: a.nothingChanged });
      return;
    }
    setBusy(true);
    const res = await apiFetch<{ message: string; data: AdminGalleryImage }>(`/admin/gallery/images/${item.id}`, { method: "PATCH", body });
    setBusy(false);
    if (res.ok) {
      replace(res.data.data);
      setEditing(null);
      setFlash({ tone: "success", text: res.data.message });
      if (body.category) onChanged();
      return;
    }
    const errors = fieldErrors(res);
    setEditErrors({ title_bn: errors.title_bn, title_en: errors.title_en, category: errors.category });
    setEditError(Object.keys(errors).length ? t.auth.fixErrors : failureText(res, t));
  }

  async function remove(item: AdminGalleryImage) {
    setBusy(true);
    setDeleteError(null);
    const res = await apiFetch<{ message: string }>(`/admin/gallery/images/${item.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      setDeleteError(failureText(res, t));
      return;
    }
    setDeleting(null);
    setFlash({ tone: "success", text: res.data.message });
    onChanged();
    // Reload so the next page's first photo moves up; go back a page if this one is now empty.
    if (items.length === 1 && page > 1) setPage(page - 1);
    else setReloadKey((k) => k + 1);
  }

  const setDraft = (patch: Partial<Draft>) => {
    setEditing((cur) => (cur ? { ...cur, draft: { ...cur.draft, ...patch } } : cur));
    setEditErrors((er) => ({ ...er, ...Object.fromEntries(Object.keys(patch).map((k) => [k, undefined])) }));
  };

  return (
    <Card className="p-5 sm:p-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h2 className="text-lg font-semibold">{a.listTitle}</h2>
          <p role="status" className="mt-1 text-sm text-muted">
            {current?.ok ? fill(a.total, { n: digits(current.total) }) : !current ? t.gallery.loading : ""}
          </p>
        </div>
        <div className="grid w-full gap-3 sm:w-auto sm:grid-cols-2">
          <Select label={a.filterCategory} value={category} onChange={(e) => filter(setCategory)(e.target.value)} className="sm:w-52">
            <option value="">{a.allCategories}</option>
            {categories.map((c) => (
              <option key={c.key} value={c.key}>
                {categoryName(c, locale)}
              </option>
            ))}
          </Select>
          <Select label={a.filterStatus} value={status} onChange={(e) => filter(setStatus)(e.target.value)} className="sm:w-44">
            <option value="">{a.allStatuses}</option>
            <option value="published">{a.published}</option>
            <option value="unpublished">{a.hidden}</option>
          </Select>
        </div>
      </div>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {!current ? (
        <ul className="mt-5 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4" aria-busy="true">
          {Array.from({ length: 8 }, (_, i) => (
            <li key={i} className="h-64 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : !current.ok ? (
        <div className="mt-5 text-center">
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {t.gallery.retry}
          </Button>
        </div>
      ) : items.length === 0 ? (
        <p className="mt-5 text-muted">{a.empty}</p>
      ) : (
        <>
          {items.length > 1 && <p className="mt-4 text-sm text-muted">{a.dragHint}</p>}
          <ul className="mt-4 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-4">
            {items.map((item, i) => {
              const title = photoTitle(item, locale);
              return (
                <li
                  key={item.id}
                  draggable={!busy}
                  onDragStart={() => {
                    dragFrom.current = i;
                  }}
                  onDragOver={(e) => e.preventDefault()}
                  onDrop={(e) => {
                    e.preventDefault();
                    if (dragFrom.current !== null) move(dragFrom.current, i);
                    dragFrom.current = null;
                  }}
                  className="flex cursor-grab flex-col overflow-hidden rounded-card border border-border bg-surface shadow-card active:cursor-grabbing"
                >
                  <div className={`relative aspect-[4/3] bg-border ${item.is_published ? "" : "opacity-60"}`}>
                    <Image src={item.thumb_url} alt="" fill unoptimized sizes="300px" className="pointer-events-none object-cover" />
                    <span
                      className={`absolute top-2 left-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${
                        item.is_published ? "bg-success-soft text-success" : "bg-surface text-muted"
                      }`}
                    >
                      {item.is_published ? <span className="size-1.5 rounded-full bg-success" /> : <Icon name="eyeOff" className="size-3.5" />}
                      {item.is_published ? a.published : a.hidden}
                    </span>
                  </div>
                  <div className="flex flex-1 flex-col p-3">
                    <p className={`line-clamp-2 text-sm font-semibold break-words ${title ? "text-heading" : "text-muted italic"}`}>{title ?? a.noTitle}</p>
                    <p className="eyebrow mt-1">{categoryName(item.category, locale)}</p>
                    <div className="mt-auto flex flex-wrap gap-1.5 pt-3">
                      <Button variant="outline" size="sm" className="px-2.5" aria-label={a.moveUp} title={a.moveUp} disabled={busy || i === 0} onClick={() => move(i, i - 1)}>
                        <Icon name="arrowUp" className="size-4" />
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        className="px-2.5"
                        aria-label={a.moveDown}
                        title={a.moveDown}
                        disabled={busy || i === items.length - 1}
                        onClick={() => move(i, i + 1)}
                      >
                        <Icon name="arrowDown" className="size-4" />
                      </Button>
                      <Button variant="outline" size="sm" className="px-2.5" aria-label={a.edit} title={a.edit} disabled={busy} onClick={() => openEdit(item)}>
                        <Icon name="pencil" className="size-4" />
                      </Button>
                      <Button
                        variant="outline"
                        size="sm"
                        className="px-2.5"
                        aria-label={item.is_published ? a.hide : a.publish}
                        title={item.is_published ? a.hide : a.publish}
                        disabled={busy}
                        onClick={() => void togglePublish(item)}
                      >
                        <Icon name={item.is_published ? "eyeOff" : "eye"} className="size-4" />
                      </Button>
                      <Button
                        variant="danger"
                        size="sm"
                        className="px-2.5"
                        aria-label={a.delete}
                        title={a.delete}
                        disabled={busy}
                        onClick={() => {
                          setDeleteError(null);
                          setDeleting(item);
                        }}
                      >
                        <Icon name="trash" className="size-4" />
                      </Button>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
          {current.last > 1 && (
            <div className="mt-5 flex flex-wrap items-center justify-center gap-3">
              <Button variant="outline" size="sm" disabled={busy || page <= 1} onClick={() => setPage(page - 1)}>
                <Icon name="chevronLeft" className="size-4" />
                {a.prevPage}
              </Button>
              <span className="text-sm text-muted">{localizeDigits(fill(a.page, { page: current.page, last: current.last }), locale)}</span>
              <Button variant="outline" size="sm" disabled={busy || page >= current.last} onClick={() => setPage(page + 1)}>
                {a.nextPage}
                <Icon name="chevronRight" className="size-4" />
              </Button>
            </div>
          )}
        </>
      )}

      {editing && (
        <Modal title={a.editTitle} onClose={() => !busy && setEditing(null)}>
          <form onSubmit={saveEdit} noValidate className="space-y-4">
            <div className="relative aspect-[4/3] w-full overflow-hidden rounded-xl bg-border">
              <Image src={editing.item.thumb_url} alt="" fill unoptimized sizes="400px" className="object-cover" />
            </div>
            <Input label={a.titleBn} value={editing.draft.title_bn} onChange={(e) => setDraft({ title_bn: e.target.value })} error={editErrors.title_bn} maxLength={150} />
            <Input label={a.titleEn} value={editing.draft.title_en} onChange={(e) => setDraft({ title_en: e.target.value })} error={editErrors.title_en} maxLength={150} />
            <Select label={a.category} value={editing.draft.category} onChange={(e) => setDraft({ category: e.target.value })} error={editErrors.category}>
              {categories.map((c) => (
                <option key={c.key} value={c.key}>
                  {categoryName(c, locale)}
                </option>
              ))}
            </Select>
            {editError && <Alert tone="danger">{editError}</Alert>}
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="ghost" disabled={busy} onClick={() => setEditing(null)}>
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
        <Modal title={a.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <div className="flex gap-3">
            <div className="relative size-20 shrink-0 overflow-hidden rounded-lg bg-border">
              <Image src={deleting.thumb_url} alt="" fill unoptimized sizes="80px" className="object-cover" />
            </div>
            <p>{a.deleteText}</p>
          </div>
          {deleteError && (
            <Alert tone="danger" className="mt-4">
              {deleteError}
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
