"use client";

import { useEffect, useState } from "react";
import { useDictionary } from "@/i18n/client";
import { RequireAuth } from "@/components/auth/require-auth";
import { ButtonLink } from "@/components/ui/button";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type GalleryCategory, type Role } from "@/lib/api";
import { GalleryCategories } from "./gallery-categories";
import { GalleryPhotoList } from "./gallery-photo-list";
import { GalleryUploader } from "./gallery-uploader";

// Gallery management: admin + super_admin only (owner, Phase 6b — the committee has no admin powers).
const ROLES: readonly Role[] = ["admin", "super_admin"];
const TABS = ["photos", "categories"] as const;

/** /dashboard/gallery */
export function GalleryAdminScreen() {
  return <RequireAuth roles={ROLES}>{() => <GalleryAdmin />}</RequireAuth>;
}

function GalleryAdmin() {
  const t = useDictionary();
  const a = t.gallery.admin;
  const [tab, setTab] = useState<(typeof TABS)[number]>("photos");
  const [photosReload, setPhotosReload] = useState(0);

  // Categories (with photo counts) — shared by the upload picker, the list filter and the Categories tab.
  const [categoriesReload, setCategoriesReload] = useState(0);
  const [categories, setCategories] = useState<{ list: GalleryCategory[] | null; failed: boolean }>({ list: null, failed: false });
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: GalleryCategory[] }>("/admin/gallery/categories").then((res) => {
      if (cancelled) return;
      setCategories((prev) => (res.ok ? { list: res.data.data, failed: false } : { list: prev.list, failed: true }));
    });
    return () => {
      cancelled = true;
    };
  }, [categoriesReload]);
  const reloadCategories = () => setCategoriesReload((k) => k + 1);
  const list = categories.list ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <p className="max-w-3xl">{a.subtitle}</p>
        <ButtonLink href="/gallery" variant="outline" size="sm" target="_blank" rel="noopener">
          <Icon name="eye" className="size-4" />
          {a.viewPublic}
        </ButtonLink>
      </div>

      <div role="tablist" aria-label={a.tabsLabel} className="flex flex-wrap gap-2">
        {TABS.map((key) => {
          const active = key === tab;
          return (
            <button
              key={key}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => setTab(key)}
              className={`shrink-0 rounded-full border px-4 py-2 text-sm font-semibold whitespace-nowrap transition-colors ${
                active
                  ? "border-secondary bg-secondary text-secondary-foreground"
                  : "border-border bg-surface text-foreground hover:border-primary hover:text-primary-text"
              }`}
            >
              {key === "photos" ? a.tabPhotos : a.tabCategories}
            </button>
          );
        })}
      </div>

      <div role="tabpanel" className="space-y-6">
        {tab === "photos" ? (
          <>
            <GalleryUploader
              categories={list}
              onUploaded={() => {
                setPhotosReload((k) => k + 1);
                reloadCategories();
              }}
            />
            <GalleryPhotoList categories={list} reloadSignal={photosReload} onChanged={reloadCategories} />
          </>
        ) : (
          <GalleryCategories categories={categories.list} failed={categories.failed && !categories.list} onChanged={reloadCategories} />
        )}
      </div>
    </div>
  );
}
