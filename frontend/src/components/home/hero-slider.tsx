"use client";

import Image from "next/image";
import { useCallback, useEffect, useRef, useState } from "react";
import { useDictionary, useLocale } from "@/i18n/client";
import { localizeDigits } from "@/lib/format";
import type { HeroSlide } from "@/lib/api";
import { heroOverlayBackgrounds } from "./hero-overlay";

const AUTOPLAY_MS = 5000;
const SWIPE_MIN_PX = 40;

function usePrefersReducedMotion() {
  const [reduced, setReduced] = useState(false);
  useEffect(() => {
    const query = window.matchMedia("(prefers-reduced-motion: reduce)");
    const update = () => setReduced(query.matches);
    update();
    query.addEventListener("change", update);
    return () => query.removeEventListener("change", update);
  }, []);
  return reduced;
}

/**
 * Hero background slider: the admin hero slides fill the whole Home hero section as a
 * crossfading background (object-cover) behind the hero text, which is passed as `children`.
 * 5 s autoplay, pause on hover/focus, arrows, dots, ←/→ keys, swipe, no autoplay/animation
 * with reduced motion. Only rendered when there are slides. `reserveBottom` lifts the dots
 * and caption above the stat cards that overlap the bottom of the hero. `overlay` = admin
 * "Overlay darkness" (0–80, default 40) scaling the shade over the pictures.
 */
export function HeroSlider({
  slides,
  reserveBottom,
  overlay,
  children,
}: {
  slides: HeroSlide[];
  reserveBottom: boolean;
  overlay: number;
  children: React.ReactNode;
}) {
  const t = useDictionary();
  const locale = useLocale();
  const reducedMotion = usePrefersReducedMotion();
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const touchStartX = useRef<number | null>(null);
  const count = slides.length;

  const go = useCallback(
    (next: number) => setIndex(((next % count) + count) % count),
    [count],
  );

  useEffect(() => {
    if (count < 2 || paused || reducedMotion) return;
    const timer = setTimeout(() => go(index + 1), AUTOPLAY_MS);
    return () => clearTimeout(timer);
  }, [index, count, paused, reducedMotion, go]);

  const text = (bn: string | null, en: string | null) =>
    (locale === "en" ? en || bn : bn || en) ?? "";
  const caption = text(slides[index].title_bn, slides[index].title_en);
  const shade = heroOverlayBackgrounds(overlay);
  const lift = reserveBottom ? "bottom-20 sm:bottom-24" : "bottom-4";

  return (
    <section
      aria-roledescription="carousel"
      aria-label={t.home.slider.label}
      className="relative isolate overflow-hidden bg-hero text-white"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
      onTouchStart={(e) => {
        touchStartX.current = e.touches[0].clientX;
      }}
      onTouchEnd={(e) => {
        const start = touchStartX.current;
        touchStartX.current = null;
        if (start === null) return;
        const delta = e.changedTouches[0].clientX - start;
        if (Math.abs(delta) >= SWIPE_MIN_PX) go(index + (delta < 0 ? 1 : -1));
      }}
      onKeyDown={(e) => {
        if (e.key === "ArrowLeft") go(index - 1);
        if (e.key === "ArrowRight") go(index + 1);
      }}
    >
      <div className="absolute inset-0 -z-10">
        {slides.map((slide, i) => {
          const active = i === index;
          return (
            <div
              key={slide.id}
              role="group"
              aria-roledescription="slide"
              aria-label={localizeDigits(`${i + 1} / ${count}`, locale)}
              aria-hidden={!active}
              className={`absolute inset-0 ${reducedMotion ? "" : "transition-opacity duration-700 ease-in-out"} ${active ? "opacity-100" : "opacity-0"}`}
            >
              <Image
                src={slide.image_url}
                alt=""
                fill
                sizes="100vw"
                preload={i === 0}
                loading={i === 0 ? undefined : "lazy"}
                className="object-cover"
              />
            </div>
          );
        })}
        {/* Overlay: darker behind the text on the left, light on the right (desktop); even on phones */}
        <div className="absolute inset-0 hidden lg:block" style={{ background: shade.desktop }} />
        <div className="absolute inset-0 lg:hidden" style={{ background: shade.mobile }} />
      </div>

      {children}

      {count > 1 && (
        <>
          <ArrowButton
            side="left"
            label={t.home.slider.previous}
            onClick={() => go(index - 1)}
          />
          <ArrowButton
            side="right"
            label={t.home.slider.next}
            onClick={() => go(index + 1)}
          />
        </>
      )}
      <div className={`absolute inset-x-0 ${lift} z-20 flex flex-col items-center gap-1 px-12`}>
        {caption && (
          <p className="max-w-full truncate text-xs text-white/80 drop-shadow">{caption}</p>
        )}
        {count > 1 && (
          <div className="flex justify-center gap-1">
            {slides.map((slide, i) => (
              <button
                key={slide.id}
                type="button"
                aria-label={t.home.slider.goTo.replace(
                  "{n}",
                  localizeDigits(String(i + 1), locale),
                )}
                aria-current={i === index}
                onClick={() => go(i)}
                className="flex size-7 items-center justify-center rounded-full focus-visible:outline-2 focus-visible:outline-white"
              >
                <span
                  className={`block h-2.5 rounded-full transition-all ${i === index ? "w-6 bg-primary" : "w-2.5 bg-white/60 hover:bg-white"}`}
                />
              </button>
            ))}
          </div>
        )}
      </div>
    </section>
  );
}

function ArrowButton({
  side,
  label,
  onClick,
}: {
  side: "left" | "right";
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      onClick={onClick}
      className={`absolute top-1/2 z-20 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/30 text-white transition-colors hover:bg-black/50 focus-visible:outline-2 focus-visible:outline-white ${side === "left" ? "left-1 sm:left-3" : "right-1 sm:right-3"}`}
    >
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        className="size-4"
        fill="none"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      >
        <path d={side === "left" ? "M15 18l-6-6 6-6" : "M9 18l6-6-6-6"} />
      </svg>
    </button>
  );
}
