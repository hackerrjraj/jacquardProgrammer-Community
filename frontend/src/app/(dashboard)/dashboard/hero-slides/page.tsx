import { dashboardMetadata } from "@/i18n/server";
import { HeroSlidesAdminScreen } from "@/components/hero-slides/hero-slides-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("heroSlides");

// Home page hero slides (Phase 11b-1) — admin / super_admin only.
export default function Page() {
  return <HeroSlidesAdminScreen />;
}
