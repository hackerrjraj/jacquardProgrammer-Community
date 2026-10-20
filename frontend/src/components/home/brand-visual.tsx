import Image from "next/image";
import { Icon, type IconName } from "@/components/ui/icon";
import { LogoBadge } from "@/components/layout/logo";
import { getSiteImage } from "@/lib/site-images";

const ICONS: IconName[] = ["chip", "cog", "code"];

/**
 * Picture box used where the screenshots show a stock factory photo (Home "About" block, About "Who we are").
 * The admin's "About section picture" (Site Content → Images) replaces the decorative navy card (grid + logo badge +
 * machine icons) once uploaded.
 */
export async function BrandVisual({ caption }: { caption: string }) {
  const image = await getSiteImage("about_image");
  if (image) {
    return (
      <div className="relative isolate aspect-[4/3] overflow-hidden rounded-card shadow-image ring-1 ring-black/5">
        <Image src={image} alt={caption} fill sizes="(min-width: 1024px) 560px, 100vw" className="object-cover" />
      </div>
    );
  }
  return (
    <div
      aria-hidden="true"
      className="relative isolate aspect-[4/3] overflow-hidden rounded-card bg-hero shadow-image ring-1 ring-black/5"
    >
      <div className="absolute inset-0 flex flex-col items-center justify-center gap-5 p-6 text-center">
        <LogoBadge className="size-20! text-sm! shadow-panel" />
        <div className="flex gap-3">
          {ICONS.map((name) => (
            <span
              key={name}
              className="flex size-12 items-center justify-center rounded-control bg-white/10 text-primary ring-1 ring-white/15"
            >
              <Icon name={name} className="size-6" />
            </span>
          ))}
        </div>
        <p className="max-w-xs font-heading text-sm leading-snug font-semibold text-white/85">
          {caption}
        </p>
      </div>
    </div>
  );
}
