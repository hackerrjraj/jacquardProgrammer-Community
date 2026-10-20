import { getDictionary, pageMetadata } from "@/i18n/server";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import { RegisterForm } from "@/components/auth/register-form";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("register");

export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero overlap eyebrow={t.eyebrows.register} title={t.pages.register} subtitle={t.register.subtitle} />
      <Container className="pb-12 sm:pb-16">
        <RegisterForm />
      </Container>
    </>
  );
}
