"use client";

import Image from "next/image";
import { useEffect, useRef } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { Icon } from "@/components/ui/icon";
import type { GalleryImage } from "@/lib/api";
import { fill } from "@/lib/auth";
import { localizeDigits } from "@/lib/format";
import { categoryName, photoTitle } from "./gallery-shared";

const SWIPE_PX = 40;

/**
 * Gallery lightbox (same look as the programmer-profile portfolio lightbox): large version,
 * ← / → buttons and keys, swipe on touch screens, Esc / backdrop / × closes. Wraps around.
 */
export function GalleryLightbox({
  items,
  index,
  onChange,
  onClose,
}: {
  items: GalleryImage[];
  index: number;
  onChange: (i: number) => void;
  onClose: () => void;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const g = t.gallery;
  const ref = useRef<HTMLDialogElement>(null);
  const touchX = useRef<number | null>(null);
  const item = items[index];
  const count = items.length;
  const title = item ? photoTitle(item, locale) : null;
  const digits = (n: number) => localizeDigits(String(n), locale);

  useEffect(() => {
    const dialog = ref.current;
    if (dialog && !dialog.open) dialog.showModal();
  }, []);

  if (!item) return null;
  const move = (step: number) => onChange((index + step + count) % count);
  const neighbour = count > 1 ? items[(index + 1) % count] : null;

  return (
    <dialog
      ref={ref}
      aria-label={title ?? fill(g.photoAlt, { n: digits(index + 1) })}
      onCancel={(e) => {
        e.preventDefault();
        onClose();
      }}
      onClick={(e) => {
        if (e.target === ref.current) onClose();
      }}
      onKeyDown={(e) => {
        if (count > 1 && e.key === "ArrowLeft") move(-1);
        if (count > 1 && e.key === "ArrowRight") move(1);
      }}
      className="m-auto w-[calc(100%-1rem)] max-w-5xl overflow-hidden rounded-card bg-surface p-0 text-text shadow-panel backdrop:bg-navy/90 sm:w-[calc(100%-2rem)]"
    >
      <div
        className="relative h-[min(70vh,48rem)] w-full touch-pan-y bg-navy"
        onTouchStart={(e) => {
          touchX.current = e.touches[0].clientX;
        }}
        onTouchEnd={(e) => {
          if (touchX.current === null || count < 2) return;
          const dx = e.changedTouches[0].clientX - touchX.current;
          touchX.current = null;
          if (Math.abs(dx) >= SWIPE_PX) move(dx < 0 ? 1 : -1);
        }}
      >
        <Image
          key={item.id}
          src={item.image_url}
          alt={title ?? fill(g.photoAlt, { n: digits(index + 1) })}
          fill
          unoptimized
          sizes="(min-width: 1024px) 1024px, 100vw"
          className="object-contain"
        />
        {/* Load the next photo early so "next" feels instant. */}
        {neighbour && (
          // eslint-disable-next-line @next/next/no-img-element -- invisible preload only
          <img src={neighbour.image_url} alt="" aria-hidden="true" className="hidden" />
        )}
        <button
          type="button"
          onClick={onClose}
          aria-label={g.close}
          className="absolute top-3 right-3 flex size-10 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-primary"
        >
          <Icon name="close" className="size-5" />
        </button>
        {count > 1 && (
          <>
            <button
              type="button"
              onClick={() => move(-1)}
              aria-label={g.prev}
              className="absolute top-1/2 left-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-primary"
            >
              <Icon name="chevronLeft" className="size-5" />
            </button>
            <button
              type="button"
              onClick={() => move(1)}
              aria-label={g.next}
              className="absolute top-1/2 right-3 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-navy/70 text-white hover:bg-navy focus-visible:outline-2 focus-visible:outline-primary"
            >
              <Icon name="chevronRight" className="size-5" />
            </button>
          </>
        )}
      </div>
      <div className="flex items-start justify-between gap-4 px-5 py-4">
        <div className="min-w-0">
          <p className="eyebrow">{categoryName(item.category, locale)}</p>
          {title && <p className="mt-1 font-heading text-lg font-semibold break-words text-heading">{title}</p>}
        </div>
        {count > 1 && (
          <p className="shrink-0 text-sm text-muted" aria-live="polite">
            {localizeDigits(fill(g.count, { n: index + 1, total: count }), locale)}
          </p>
        )}
      </div>
    </dialog>
  );
}
