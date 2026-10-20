import { getDictionary, pageMetadata } from "@/i18n/server";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import { LoginForm } from "@/components/auth/login-form";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("login");

export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero overlap eyebrow={t.eyebrows.login} title={t.pages.login} subtitle={t.login.subtitle} />
      <Container className="pb-12 sm:pb-16">
        <LoginForm />
      </Container>
    </>
  );
}
