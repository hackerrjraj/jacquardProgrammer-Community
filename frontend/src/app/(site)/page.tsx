import { Suspense } from "react";
import { homeMetadata } from "@/i18n/server";
import {
  AboutBlock,
  CallToAction,
  Expertise,
  ExpertiseSkeleton,
  GalleryPreview,
  HeroPlaceholder,
  HomeCommittee,
  HomeHero,
  HomeProgrammers,
  LatestNotices,
} from "@/components/home/home-sections";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => homeMetadata();

// Home — MASTER_SPEC §13 / Home screenshot. Every section that needs the API is in
// its own <Suspense>, so a slow API never blocks the page; a section with no data is hidden.
export default function Home() {
  return (
    <>
      <Suspense fallback={<HeroPlaceholder />}>
        <HomeHero />
      </Suspense>

      <AboutBlock />

      <Suspense fallback={null}>
        <HomeCommittee />
      </Suspense>

      <Suspense fallback={null}>
        <HomeProgrammers />
      </Suspense>

      <Suspense fallback={<ExpertiseSkeleton />}>
        <Expertise />
      </Suspense>

      <Suspense fallback={null}>
        <GalleryPreview />
      </Suspense>

      <Suspense fallback={null}>
        <LatestNotices />
      </Suspense>

      <CallToAction />
    </>
  );
}
