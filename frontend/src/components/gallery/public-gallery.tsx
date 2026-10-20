"use client";

import Image from "next/image";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Button } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Icon } from "@/components/ui/icon";
import { apiFetch, type GalleryCategory, type GalleryImage, type Paginated } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { GalleryLightbox } from "./gallery-lightbox";
import { CATEGORY_KEY, categoryName, photoTitle } from "./gallery-shared";

type Loaded = { key: string; ok: true; items: GalleryImage[]; page: number; last: number; total: number } | { key: string; ok: false; status: number };

/** Grey tiles while the photos load (same grid as the photos). */
export function GallerySkeleton({ count = 9 }: { count?: number }) {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3" aria-busy="true">
      {Array.from({ length: count }, (_, i) => (
        <li key={i} className="aspect-[4/3] animate-pulse rounded-2xl bg-border" />
      ))}
    </ul>
  );
}

/**
 * Public Gallery (gallery screenshot, MASTER_SPEC §23): category chips ("All" + admin categories),
 * photo grid of thumbnails, "Load more" (24 per page from the API), lightbox with the large version.
 * The chosen category is in the URL (?category=events), so links can be shared.
 */
export function PublicGallery() {
  const t = useDictionary();
  const locale = useLocale();
  const g = t.gallery;
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const digits = (n: number) => localizeDigits(String(n), locale);

  const raw = searchParams.get("category") ?? "";
  const wanted = CATEGORY_KEY.test(raw) && raw.length <= 50 ? raw : "";

  // Categories (tabs). null = loading, "failed" = could not load (photos still load).
  const [categories, setCategories] = useState<GalleryCategory[] | "failed" | null>(null);
  useEffect(() => {
    let cancelled = false;
    void apiFetch<{ data: GalleryCategory[] }>("/public/gallery/categories").then((res) => {
      if (!cancelled) setCategories(res.ok ? res.data.data : "failed");
    });
    return () => {
      cancelled = true;
    };
  }, []);

  // A category from the URL that does not exist (any more) shows "All" instead of an error.
  // While the tabs are still loading we wait, so an unknown key never reaches the API.
  const category: string | null = !wanted
    ? ""
    : categories === null
      ? null
      : categories === "failed" || categories.some((c) => c.key === wanted)
        ? wanted
        : "";

  const [reloadKey, setReloadKey] = useState(0);
  const requestKey = `${category}#${reloadKey}`;
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  useEffect(() => {
    if (category === null) return;
    let cancelled = false;
    const q = category ? `?category=${encodeURIComponent(category)}` : "";
    void apiFetch<Paginated<GalleryImage>>(`/public/gallery${q}`).then((res) => {
      if (cancelled) return;
      setLoaded(
        res.ok
          ? { key: requestKey, ok: true, items: res.data.data, page: res.data.meta.current_page, last: res.data.meta.last_page, total: res.data.meta.total }
          : { key: requestKey, ok: false, status: res.status },
      );
    });
    return () => {
      cancelled = true;
    };
  }, [category, requestKey]);
  const current = loaded?.key === requestKey ? loaded : null;

  // "Load more": next page appended to the same list.
  const [moreState, setMoreState] = useState<"idle" | "busy" | "failed">("idle");
  const latestKey = useRef(requestKey);
  useEffect(() => {
    latestKey.current = requestKey;
  }, [requestKey]);
  async function loadMore() {
    if (!current?.ok || moreState === "busy") return;
    const key = requestKey;
    setMoreState("busy");
    const params = new URLSearchParams({ page: String(current.page + 1) });
    if (category) params.set("category", category);
    const res = await apiFetch<Paginated<GalleryImage>>(`/public/gallery?${params}`);
    if (latestKey.current !== key) return; // the tab changed meanwhile
    if (!res.ok) {
      setMoreState("failed");
      return;
    }
    setMoreState("idle");
    setLoaded((prev) =>
      prev?.ok && prev.key === key
        ? {
            ...prev,
            // Skip any photo already shown (the order can shift if an admin uploads meanwhile).
            items: [...prev.items, ...res.data.data.filter((n) => !prev.items.some((p) => p.id === n.id))],
            page: res.data.meta.current_page,
            last: res.data.meta.last_page,
            total: res.data.meta.total,
          }
        : prev,
    );
  }

  const [lightbox, setLightbox] = useState<number | null>(null);

  function choose(key: string) {
    setLightbox(null);
    setMoreState("idle");
    router.push(key ? `${pathname}?category=${key}` : pathname, { scroll: false });
  }

  const tabs = Array.isArray(categories) ? categories : [];
  const chip = (key: string, label: string) => {
    const active = (category ?? wanted) === key;
    return (
      <button
        key={key || "all"}
        type="button"
        aria-pressed={active}
        onClick={() => choose(key)}
        className={`h-9 shrink-0 rounded-full border px-4 text-xs font-semibold uppercase tracking-(--caps-tracking) transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary ${
          active ? "border-secondary bg-secondary text-white" : "border-border bg-background text-heading hover:border-primary hover:text-primary-text"
        }`}
      >
        {label}
      </button>
    );
  };

  return (
    <div className="space-y-6">
      <div role="group" aria-label={g.tabsLabel} className="flex flex-wrap gap-2">
        {chip("", g.all)}
        {tabs.map((c) => chip(c.key, categoryName(c, locale)))}
        {categories === null && <span className="h-9 w-24 animate-pulse rounded-full bg-border" aria-hidden="true" />}
      </div>

      {!current ? (
        <>
          <p role="status" className="sr-only">
            {g.loading}
          </p>
          <GallerySkeleton />
        </>
      ) : !current.ok ? (
        <Card className="mx-auto max-w-lg p-8 text-center">
          <Icon name="alert" className="mx-auto size-8 text-danger" />
          <p className="mt-3 text-danger">{current.status === 429 ? g.tooMany : g.loadFailed}</p>
          <Button variant="outline" className="mt-5" onClick={() => setReloadKey((k) => k + 1)}>
            {g.retry}
          </Button>
        </Card>
      ) : current.items.length === 0 ? (
        <Card className="mx-auto max-w-lg p-8 text-center">
          <Icon name="image" className="mx-auto size-10 text-primary-text" />
          <p className="mt-3 font-heading text-lg font-semibold text-heading">{g.emptyTitle}</p>
          <p className="mt-1 text-muted">{category ? g.emptyCategory : g.emptyText}</p>
          {category && (
            <Button variant="outline" className="mt-5" onClick={() => choose("")}>
              {g.showAll}
            </Button>
          )}
        </Card>
      ) : (
        <>
          <ul className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3">
            {current.items.map((img, i) => {
              const title = photoTitle(img, locale);
              return (
                <li key={img.id}>
                  <button
                    type="button"
                    onClick={() => setLightbox(i)}
                    aria-label={title ? fill(g.viewPhotoTitled, { title }) : fill(g.viewPhoto, { n: digits(i + 1) })}
                    className="group relative block aspect-[4/3] w-full overflow-hidden rounded-2xl bg-border shadow-card transition hover:-translate-y-0.5 hover:shadow-card-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                  >
                    <Image
                      src={img.thumb_url}
                      alt={title ?? fill(g.photoAlt, { n: digits(i + 1) })}
                      fill
                      unoptimized
                      sizes="(min-width: 1024px) 33vw, 50vw"
                      className="object-cover transition duration-300 group-hover:scale-105"
                    />
                    {title && (
                      <span className="absolute inset-x-0 bottom-0 bg-linear-to-t from-navy/80 to-transparent px-3 pt-8 pb-2 text-left text-xs font-semibold text-white sm:text-sm">
                        <span className="line-clamp-2">{title}</span>
                      </span>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>

          <div className="flex flex-col items-center gap-3 pt-2">
            <p className="text-sm text-muted">
              {fill(g.shownOf, { shown: digits(current.items.length), total: digits(current.total) })}
            </p>
            {moreState === "failed" && <p className="text-sm text-danger">{g.loadMoreFailed}</p>}
            {current.page < current.last && (
              <Button variant="outline" disabled={moreState === "busy"} onClick={() => void loadMore()}>
                {moreState === "busy" ? g.loadingMore : moreState === "failed" ? g.retry : g.loadMore}
              </Button>
            )}
          </div>
        </>
      )}

      {lightbox !== null && current?.ok && (
        <GalleryLightbox items={current.items} index={lightbox} onChange={setLightbox} onClose={() => setLightbox(null)} />
      )}
    </div>
  );
}
