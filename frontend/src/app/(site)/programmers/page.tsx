import { Suspense } from "react";
import { getDictionary, pageMetadata } from "@/i18n/server";
import { ProgrammerCardSkeleton } from "@/components/directory/programmer-card";
import { ProgrammersDirectory } from "@/components/directory/programmers-directory";
import { Container } from "@/components/ui/container";
import { getSiteImage } from "@/lib/site-images";
import { PageHero } from "@/components/ui/page-hero";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("programmers");

/** Public Programmers directory (MASTER_SPEC §21, programmers screenshot). */
export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero bgImage={await getSiteImage("header_programmers")} overlap eyebrow={t.eyebrows.programmers} title={t.directory.heroTitle} subtitle={t.directory.heroSubtitle} />
      <Container className="pb-12 sm:pb-16">
        <Suspense
          fallback={
            <ul className="grid gap-5 pt-8 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4" aria-busy="true">
              {Array.from({ length: 6 }, (_, i) => (
                <li key={i}>
                  <ProgrammerCardSkeleton />
                </li>
              ))}
            </ul>
          }
        >
          <ProgrammersDirectory />
        </Suspense>
      </Container>
    </>
  );
}
