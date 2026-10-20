import Image from "next/image";
import { Container } from "./container";

/**
 * Dark navy page hero (gradient + faint grid + teal glow) — MASTER_SPEC §8a.
 * Inner pages: eyebrow + title + subtitle, text on the left.
 * `overlap` leaves extra room at the bottom for a panel that overlaps the hero.
 * `bgImage` (Site Content → Images) is laid under a dark navy tint so the white text stays readable.
 */
export function PageHero({
  eyebrow,
  title,
  subtitle,
  overlap = false,
  bgImage,
  children,
}: {
  eyebrow?: string;
  title: string;
  subtitle?: string;
  overlap?: boolean;
  bgImage?: string | null;
  children?: React.ReactNode;
}) {
  return (
    <section className={`relative isolate overflow-hidden bg-hero text-white ${overlap ? "pb-24 sm:pb-28" : "pb-12 sm:pb-16"}`}>
      {bgImage && (
        <>
          <Image src={bgImage} alt="" fill priority sizes="100vw" className="-z-20 object-cover" />
          <div aria-hidden="true" className="absolute inset-0 -z-10 bg-[#021d30]/75" />
        </>
      )}
      <Container className="pt-10 sm:pt-14">
        {eyebrow && <p className="eyebrow mb-3">{eyebrow}</p>}
        <h1 className="max-w-3xl text-3xl leading-tight font-bold text-white sm:text-4xl lg:text-5xl">
          {title}
        </h1>
        {subtitle && (
          <p className="mt-4 max-w-2xl text-base text-white/75 sm:text-lg">{subtitle}</p>
        )}
        {children}
      </Container>
    </section>
  );
}
