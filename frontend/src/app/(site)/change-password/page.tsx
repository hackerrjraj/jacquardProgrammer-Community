import { getDictionary, pageMetadata } from "@/i18n/server";
import { Container } from "@/components/ui/container";
import { PageHero } from "@/components/ui/page-hero";
import { ChangePasswordScreen } from "@/components/auth/change-password-form";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => pageMetadata("changePassword");

// Stays on the public layout: a forced password change must happen
// before the dashboard can be used.
export default async function Page() {
  const t = await getDictionary();
  return (
    <>
      <PageHero
        overlap
        eyebrow={t.eyebrows.changePassword}
        title={t.pages.changePassword}
        subtitle={t.changePassword.subtitle}
      />
      <Container className="pb-12 sm:pb-16">
        <ChangePasswordScreen />
      </Container>
    </>
  );
}
