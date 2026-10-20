/** Hero slider overlay (admin "Overlay darkness", whole percent 0–80, default 40). */
export const HERO_OVERLAY_DEFAULT = 40;
export const HERO_OVERLAY_MAX = 80;

// At the default (40%) the shade is ≈57.5% behind the text on the left → ≈12.5% on the right; 45% flat on phones.
// The admin value scales all of these (value / 40), so 0 = no shade and 80 = twice as dark.
const LEFT = 57.5;
const RIGHT = 12.5;
const MOBILE = 45;

const navy = (percent: number) =>
  `color-mix(in srgb, var(--color-navy) ${Math.min(100, Math.max(0, percent)).toFixed(1)}%, transparent)`;

export function clampOverlay(value: unknown): number {
  const n = typeof value === "number" && Number.isFinite(value) ? Math.round(value) : HERO_OVERLAY_DEFAULT;
  return Math.min(HERO_OVERLAY_MAX, Math.max(0, n));
}

/** CSS backgrounds for the desktop (left → right gradient) and mobile (uniform) overlay layers. */
export function heroOverlayBackgrounds(overlay: number): { desktop: string; mobile: string } {
  const k = clampOverlay(overlay) / HERO_OVERLAY_DEFAULT;
  return {
    desktop: `linear-gradient(to right, ${navy(LEFT * k)} 0%, ${navy(LEFT * k)} 35%, ${navy(RIGHT * k)} 100%)`,
    mobile: navy(MOBILE * k),
  };
}
