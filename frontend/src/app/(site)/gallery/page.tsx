import { Suspense } from "react";
import { getDictionary, pageMetadata } from "@/i18n/server";
import { GallerySkeleton, PublicGallery } from "@/components/gallery/public-gallery";
import { Container } from "@/components/ui/container";
import { getSiteImage } from "@/lib/site-images";
import { PageHero } from "@/components/ui/page-hero";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("gallery");

/** Public Gallery (MASTER_SPEC §23, gallery screenshot). */
export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero bgImage={await getSiteImage("header_gallery")} eyebrow={t.eyebrows.gallery} title={t.gallery.heroTitle} subtitle={t.gallery.heroSubtitle} />
      <Container className="py-10 sm:py-14">
        <Suspense fallback={<GallerySkeleton />}>
          <PublicGallery />
        </Suspense>
      </Container>
    </>
  );
}
