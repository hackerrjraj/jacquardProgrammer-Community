import { getDictionary } from "@/i18n/server";
import { ButtonLink } from "@/components/ui/button";
import { Card } from "@/components/ui/card";
import { Container } from "@/components/ui/container";
import { Icon } from "@/components/ui/icon";
import { PageHero } from "@/components/ui/page-hero";

/** 404 for /programmers/{id}: unknown id, or anyone not shown in the directory. */
export default async function NotFound() {
  const t = await getDictionary();
  const d = t.directory;
  return (
    <>
      <PageHero overlap eyebrow={t.eyebrows.programmers} title={d.notFoundTitle} />
      <Container className="pb-12 sm:pb-16">
        <Card className="relative mx-auto -mt-16 max-w-lg p-8 text-center shadow-panel!">
          <Icon name="user" className="mx-auto size-10 text-primary-text" />
          <p className="mt-3">{d.notFoundText}</p>
          <ButtonLink href="/programmers" variant="primary" className="mt-6">
            <Icon name="arrowLeft" className="size-4" />
            {d.back}
          </ButtonLink>
        </Card>
      </Container>
    </>
  );
}
