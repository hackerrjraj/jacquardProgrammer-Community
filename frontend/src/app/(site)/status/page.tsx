import { getDictionary, pageMetadata } from "@/i18n/server";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import ServerStatus from "./server-status";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("status");

export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero eyebrow={t.eyebrows.status} title={t.pages.status} />
      <Container className="flex justify-center py-12 sm:py-16">
        <ServerStatus />
      </Container>
    </>
  );
}
