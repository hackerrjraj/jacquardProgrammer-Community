"use client";

import Image from "next/image";
import { useEffect, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { Alert } from "@/components/ui/alert";
import { Button, ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon, type IconName } from "@/components/ui/icon";
import { Modal } from "@/components/ui/modal";
import { failureText } from "@/components/profile/profile-shared";
import { apiFetch, type AdminHeroSlide, type Role } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { HeroSlideForm } from "./hero-slide-form";
import { heroSlideTitle } from "./hero-slides-shared";

// Hero slides management: admin + super_admin only (same as the gallery / expertise cards).
const ROLES: readonly Role[] = ["admin", "super_admin"];

type Flash = { tone: "success" | "danger"; text: string } | null;
type Loaded = { key: string; ok: true; items: AdminHeroSlide[] } | { key: string; ok: false };
type View = { mode: "list" } | { mode: "form"; slide: AdminHeroSlide | null };

/** /dashboard/hero-slides */
export function HeroSlidesAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <HeroSlidesAdmin />}</RequireAuth>;
}

function HeroSlidesAdmin() {
  const t = useDictionary();
  const a = t.heroAdmin;
  const [view, setView] = useState<View>({ mode: "list" });
  const [flash, setFlash] = useState<Flash>(null);
  const [reload, setReload] = useState(0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{a.subtitle}</p>
        <div className="flex flex-wrap gap-2">
          <ButtonLink href="/" variant="outline" size="sm" target="_blank" rel="noopener">
            <Icon name="eye" className="size-4" />
            {a.viewHome}
          </ButtonLink>
          {view.mode === "list" && (
            <Button
              size="sm"
              onClick={() => {
                setFlash(null);
                setView({ mode: "form", slide: null });
              }}
            >
              <Icon name="plus" className="size-4" />
              {a.new}
            </Button>
          )}
        </div>
      </div>

      {view.mode === "form" ? (
        <HeroSlideForm
          key={view.slide?.id ?? "new"}
          slide={view.slide}
          onCancel={() => setView({ mode: "list" })}
          onDone={(message) => {
            setFlash({ tone: "success", text: message });
            setReload((k) => k + 1);
            setView({ mode: "list" });
          }}
        />
      ) : (
        <HeroSlideList
          reloadSignal={reload}
          flash={flash}
          setFlash={setFlash}
          onEdit={(slide) => {
            setFlash(null);
            setView({ mode: "form", slide });
          }}
        />
      )}
    </div>
  );
}

function HeroSlideList({
  reloadSignal,
  flash,
  setFlash,
  onEdit,
}: {
  reloadSignal: number;
  flash: Flash;
  setFlash: (f: Flash) => void;
  onEdit: (slide: AdminHeroSlide) => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const a = t.heroAdmin;
  const digits = (v: number) => localizeDigits(String(v), locale);

  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${reloadKey}|${reloadSignal}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState<AdminHeroSlide | null>(null);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: AdminHeroSlide[] }>("/admin/hero-slides").then((res) => {
      if (cancelled) return;
      setLoaded(res.ok ? { key: requestKey, ok: true, items: res.data.data } : { key: requestKey, ok: false });
    });
    return () => {
      cancelled = true;
    };
  }, [requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;
  const items = current?.ok ? current.items : [];
  const activeCount = items.filter((s) => s.is_active).length;

  const setItems = (next: AdminHeroSlide[]) => setLoaded((prev) => (prev?.ok ? { ...prev, items: next } : prev));
  const titleOf = (slide: AdminHeroSlide) => heroSlideTitle(slide, locale) ?? a.noTitle;

  async function move(from: number, to: number) {
    if (to < 0 || to >= items.length) return;
    const previous = items;
    const next = [...items];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    setItems(next); // show the new order at once
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminHeroSlide[] }>("/admin/hero-slides/order", {
      method: "PUT",
      body: { ids: next.map((s) => s.id) },
    });
    setBusy(false);
    if (res.ok) {
      setItems(res.data.data);
      setFlash({ tone: "success", text: res.data.message });
    } else {
      setItems(previous);
      setFlash({ tone: "danger", text: failureText(res, t) });
      // 422 = the list is out of date (e.g. another admin added a slide) → load it again.
      if (res.status === 422) setReloadKey((k) => k + 1);
    }
  }

  async function toggleActive(slide: AdminHeroSlide) {
    setBusy(true);
    setFlash(null);
    const res = await apiFetch<{ message: string; data: AdminHeroSlide }>(`/admin/hero-slides/${slide.id}/active`, {
      method: "POST",
      body: { is_active: !slide.is_active },
    });
    setBusy(false);
    if (res.ok) {
      setItems(items.map((s) => (s.id === slide.id ? res.data.data : s)));
      setFlash({ tone: "success", text: res.data.message });
    } else setFlash({ tone: "danger", text: failureText(res, t) });
  }

  async function remove(slide: AdminHeroSlide) {
    setBusy(true);
    setDeleteError(null);
    const res = await apiFetch<{ message: string }>(`/admin/hero-slides/${slide.id}`, { method: "DELETE" });
    setBusy(false);
    if (!res.ok) {
      setDeleteError(failureText(res, t));
      return;
    }
    setDeleting(null);
    setFlash({ tone: "success", text: res.data.message });
    setReloadKey((k) => k + 1);
  }

  const iconButton = (label: string, icon: IconName, onClick: () => void, opts: { danger?: boolean; disabled?: boolean } = {}) => (
    <Button
      variant={opts.danger ? "danger" : "outline"}
      size="sm"
      className="px-2.5"
      aria-label={label}
      title={label}
      disabled={busy || opts.disabled}
      onClick={onClick}
    >
      <Icon name={icon} className="size-4" />
    </Button>
  );

  return (
    <Card className="p-5 sm:p-6">
      <div>
        <h2 className="text-lg font-semibold">{a.listTitle}</h2>
        <p role="status" className="mt-1 text-sm text-muted">
          {current?.ok ? fill(a.total, { n: digits(items.length), active: digits(activeCount) }) : !current ? a.loading : ""}
        </p>
      </div>

      {flash && (
        <Alert tone={flash.tone} className="mt-4">
          {flash.text}
        </Alert>
      )}

      {!current ? (
        <ul className="mt-5 space-y-3" aria-busy="true">
          {Array.from({ length: 3 }, (_, i) => (
            <li key={i} className="h-28 animate-pulse rounded-card bg-border/60" />
          ))}
        </ul>
      ) : !current.ok ? (
        <div className="mt-5 text-center">
          <p className="text-danger">{a.loadFailed}</p>
          <Button variant="outline" className="mt-4" onClick={() => setReloadKey((k) => k + 1)}>
            {a.retry}
          </Button>
        </div>
      ) : items.length === 0 ? (
        <p className="mt-5 text-muted">{a.empty}</p>
      ) : (
        <>
          {activeCount === 0 && (
            <Alert tone="warning" className="mt-4">
              {a.noneActive}
            </Alert>
          )}
          {items.length > 1 && <p className="mt-4 text-sm text-muted">{a.orderHint}</p>}
          {/* Cards on phones, one row per slide from the md width up */}
          <ol className="mt-4 space-y-3">
            {items.map((slide, i) => {
              const title = heroSlideTitle(slide, locale);
              return (
                <li key={slide.id} className="flex flex-col gap-4 rounded-card border border-border p-4 md:flex-row md:items-center">
                  <div className="flex min-w-0 flex-1 flex-col gap-3 sm:flex-row sm:items-center">
                    <div
                      className={`relative aspect-[4/3] w-full shrink-0 overflow-hidden rounded-control bg-border sm:w-36 ${slide.is_active ? "" : "opacity-60"}`}
                    >
                      <Image src={slide.image_url} alt="" fill unoptimized sizes="(min-width: 640px) 144px, 100vw" className="object-cover" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-xs font-semibold text-muted">#{digits(i + 1)}</span>
                        {slide.is_active ? (
                          <span className="inline-flex items-center gap-1 rounded-full bg-success-soft px-2.5 py-0.5 text-xs font-semibold text-success">
                            <span className="size-1.5 rounded-full bg-success" />
                            {a.statusShown}
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 rounded-full bg-background px-2.5 py-0.5 text-xs font-semibold text-muted ring-1 ring-border">
                            <Icon name="eyeOff" className="size-3.5" />
                            {a.statusHidden}
                          </span>
                        )}
                      </div>
                      <p className={`mt-1.5 font-semibold break-words ${title ? "text-heading" : "text-muted italic"}`}>{titleOf(slide)}</p>
                      {slide.button_link && (
                        <p className="mt-0.5 flex items-center gap-1 text-sm break-all text-muted">
                          <Icon name="external" className="size-3.5 shrink-0" />
                          {slide.button_link}
                        </p>
                      )}
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 md:justify-end md:border-0 md:pt-0">
                    <button
                      type="button"
                      role="switch"
                      aria-checked={slide.is_active}
                      disabled={busy}
                      onClick={() => void toggleActive(slide)}
                      className="inline-flex items-center gap-2 rounded-control text-sm font-medium text-heading disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      <span
                        aria-hidden="true"
                        className={`inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition-colors ${slide.is_active ? "bg-primary" : "bg-border"}`}
                      >
                        <span className={`size-6 rounded-full bg-white shadow transition-transform ${slide.is_active ? "translate-x-5" : "translate-x-0"}`} />
                      </span>
                      {a.activeLabel}
                    </button>
                    <div className="flex flex-wrap gap-1.5">
                      {iconButton(a.moveUp, "arrowUp", () => void move(i, i - 1), { disabled: i === 0 })}
                      {iconButton(a.moveDown, "arrowDown", () => void move(i, i + 1), { disabled: i === items.length - 1 })}
                      {iconButton(a.edit, "pencil", () => onEdit(slide))}
                      {iconButton(
                        a.delete,
                        "trash",
                        () => {
                          setDeleteError(null);
                          setDeleting(slide);
                        },
                        { danger: true },
                      )}
                    </div>
                  </div>
                </li>
              );
            })}
          </ol>
        </>
      )}

      {deleting && (
        <Modal title={a.deleteTitle} onClose={() => !busy && setDeleting(null)}>
          <div className="flex flex-col gap-3 sm:flex-row">
            <div className="relative aspect-[4/3] w-full shrink-0 overflow-hidden rounded-control bg-border sm:w-32">
              <Image src={deleting.image_url} alt="" fill unoptimized sizes="128px" className="object-cover" />
            </div>
            <p className="min-w-0 break-words">{fill(a.deleteText, { title: titleOf(deleting) })}</p>
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
