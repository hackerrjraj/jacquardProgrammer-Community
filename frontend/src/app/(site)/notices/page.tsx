import { Suspense } from "react";
import { getDictionary, pageMetadata } from "@/i18n/server";
import { NoticesSkeleton, PublicNotices } from "@/components/notices/public-notices";
import { Container } from "@/components/ui/container";
import { getSiteImage } from "@/lib/site-images";
import { PageHero } from "@/components/ui/page-hero";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("notices");

/** Public notice board (MASTER_SPEC §24). No screenshot exists — same style as the Gallery page. */
export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero bgImage={await getSiteImage("header_notices")} eyebrow={t.eyebrows.notices} title={t.notices.heroTitle} subtitle={t.notices.heroSubtitle} />
      <Container className="py-10 sm:py-14">
        <Suspense fallback={<NoticesSkeleton />}>
          <PublicNotices />
        </Suspense>
      </Container>
    </>
  );
}
