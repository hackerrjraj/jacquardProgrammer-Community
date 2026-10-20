import { dashboardMetadata } from "@/i18n/server";
import { ExpertiseAdminScreen } from "@/components/expertise/expertise-admin";

// The language cookie makes every page render per request (see the root layout).
export const instant = false;

export const generateMetadata = () => dashboardMetadata("expertise");

// Home page Expertise cards (Phase 6d-3) — admin / super_admin only.
export default function Page() {
  return <ExpertiseAdminScreen />;
}
